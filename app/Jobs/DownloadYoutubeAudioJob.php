<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Download;
use App\Models\Setting;
use App\Services\YouTubeService;
use App\Services\AudioConverterService;
use App\Services\MetadataService;
use App\Services\DiskSpaceService;
use App\Services\WaveformService;
use App\Services\LyricsService;
use App\Services\ChapterSplitService;
use App\Exceptions\DownloadCancelledException;
use App\Exceptions\InsufficientDiskSpaceException;
use App\Exceptions\YouTubeDownloadException;
use App\Exceptions\AudioConversionException;
use App\Support\FileName;
use App\Support\TrackTitle;
use Illuminate\Support\Facades\Log;
use Exception;
use Throwable;

class DownloadYoutubeAudioJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels, Batchable;

    // Overridden from config/youtube-converter.php in the constructor
    public $timeout = 600; // 10 minutes
    public $tries = 3;
    public $backoff = [60, 300, 900]; // Exponential backoff: 1min, 5min, 15min

    /**
     * Silently drop the job if the download was deleted before it ran.
     */
    public $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Download $download
    ) {
        // YOUTUBE_JOB_TIMEOUT / YOUTUBE_JOB_TRIES in .env
        $this->timeout = max(60, (int) config('youtube-converter.job_timeout', $this->timeout));
        $this->tries = max(1, (int) config('youtube-converter.job_tries', $this->tries));
        $this->backoff = config('youtube-converter.job_backoff', $this->backoff);
    }

    /**
     * Execute the job.
     */
    public function handle(
        YouTubeService $youtubeService,
        AudioConverterService $audioConverter,
        MetadataService $metadataService,
        DiskSpaceService $diskSpaceService,
        WaveformService $waveformService,
        LyricsService $lyricsService,
        ChapterSplitService $chapterSplitService
    ): void
    {
        $tempFile = null;
        $finalFile = null;

        try {
            $this->abortIfCancelled();

            // Mark as processing
            $this->download->update([
                'status' => 'processing',
                'progress' => 0,
                'conversion_progress' => 0,
                'error_message' => null,
                'status_text' => 'Fetching video information...',
            ]);
            Log::info('Starting download job', ['download_id' => $this->download->id]);

            // Step 1: Extract video info. The create endpoint already stores the video ID
            // (and the UI sends a title), but duration/thumbnail/artist come only from yt-dlp.
            // Splitting needs the chapter list, which only yt-dlp knows
            $chapters = [];
            if ($this->needsVideoInfo() || $this->wantsChapters()) {
                $videoInfo = $youtubeService->extractVideoInfo($this->download->youtube_url);
                $chapters = $this->wantsChapters() ? ($videoInfo['chapters'] ?? []) : [];
                // Titles the user (or the UI's suggestion) set are kept as they are
                $suggested = TrackTitle::suggest($videoInfo, Setting::cleanTitles());

                $this->download->update(array_filter([
                    'youtube_id' => $this->download->youtube_id ?: $videoInfo['id'],
                    'title' => $this->hasRealTitle() ? $this->download->title : $suggested['title'],
                    'artist' => $this->download->artist ?: $suggested['artist'],
                    'album' => $this->download->album ?: $videoInfo['album'],
                    'year' => $this->download->year ?: $videoInfo['year'],
                    'genre' => $this->download->genre ?: $videoInfo['genre'],
                    'thumbnail_url' => $this->download->thumbnail_url ?: $videoInfo['thumbnail'],
                    'duration' => $videoInfo['duration'] ? (int) $videoInfo['duration'] : null,
                ], fn ($value) => $value !== null && $value !== ''));
            }

            $this->download->updateProgress(3);

            // Step 2: Check disk space (configured reserve + estimated size of this file)
            $estimatedMb = $this->download->duration
                ? $diskSpaceService->estimateFileSize(
                    $this->download->duration,
                    // Lossless formats: ~1000kbps (FLAC) and 2304kbps (24-bit/48kHz WAV) upper estimates
                    match ($this->download->format) {
                        'flac' => 1000,
                        'wav' => 2304,
                        default => (int) $this->download->quality,
                    }
                )
                : 0;

            if (!$diskSpaceService->hasEnoughSpace($estimatedMb)) {
                throw new InsufficientDiskSpaceException(
                    'Not enough disk space available. Required: ' . $this->minDiskSpaceMb() . "MB free plus ~{$estimatedMb}MB for this file."
                );
            }

            // Step 3: Prepare download paths
            $downloadPath = rtrim(Setting::downloadPath(), '/\\');
            if (!is_dir($downloadPath)) {
                mkdir($downloadPath, 0755, true);
            }

            $tempPath = storage_path('app/temp');
            if (!is_dir($tempPath)) {
                mkdir($tempPath, 0755, true);
            }

            // Save under the video's own title, e.g. "D-Sturb - LADY (Official Music Video).mp3"
            $safeFilename = FileName::fromTitle(
                $this->download->title,
                $this->download->youtube_id ?: 'download-' . $this->download->id
            );

            // Download ID keeps temp files of concurrent jobs apart
            $tempFile = $tempPath . '/download_' . $this->download->id . '_' . time();
            $finalFile = $downloadPath . '/' . $safeFilename . '.' . $this->download->format;

            // Never overwrite an earlier download: "Title (1).mp3", "Title (2).mp3", ...
            $counter = 1;
            while (file_exists($finalFile)) {
                $finalFile = $downloadPath . '/' . $safeFilename . ' (' . $counter . ').' . $this->download->format;
                $counter++;
            }

            $this->download->update(['progress' => 5, 'status_text' => 'Starting download...']);

            // Step 4: Download audio using yt-dlp
            Log::info('Downloading audio', ['url' => $this->download->youtube_url]);

            $lastReportedProgress = -1;
            try {
                $youtubeService->downloadAudio(
                    $this->download->youtube_url,
                    $tempFile,
                    $this->download->format,
                    (int) $this->download->quality,
                    function ($progress, $size = null) use (&$lastReportedProgress) {
                        // yt-dlp progress is 0 to 100; downloading is most of the wait,
                        // so it fills the 5-65 range of the bar
                        $percent = (int) $progress;
                        $sizeText = $size ? " ($size)" : "";

                        // One database update per whole percent of the download
                        if ($percent > $lastReportedProgress) {
                            // Throwing here stops the running yt-dlp process
                            $this->abortIfCancelled();

                            $this->download->update([
                                'progress' => (int) (5 + $progress * 0.6),
                                'status_text' => "Downloading... {$percent}%{$sizeText}",
                            ]);
                            $lastReportedProgress = $percent;
                        }
                    }
                );
            } catch (DownloadCancelledException $e) {
                throw $e;
            } catch (Exception $e) {
                throw new YouTubeDownloadException(
                    'Failed to download audio from YouTube: ' . $e->getMessage()
                );
            }

            $this->abortIfCancelled();

            $this->download->update([
                'progress' => 65,
                'status_text' => 'Download complete. Preparing for conversion...',
            ]);

            // Step 5: Find the downloaded file (yt-dlp adds extension)
            $downloadedFile = null;
            foreach (glob($tempFile . '*') as $file) {
                if (is_file($file)) {
                    $downloadedFile = $file;
                    break;
                }
            }

            if (!$downloadedFile || !file_exists($downloadedFile)) {
                throw new YouTubeDownloadException('Downloaded file not found');
            }

            $originalFileSize = filesize($downloadedFile);
            $this->download->update(['original_file_size' => $originalFileSize]);

            // Step 6: Convert if necessary (yt-dlp downloads the raw audio stream, e.g. webm/m4a).
            // Trimming, silence removal or volume levelling always needs a re-encode.
            $editOptions = $this->editOptions();
            $isEdited = $editOptions['trim_start'] || $editOptions['trim_end'] || $editOptions['remove_silence'] || $editOptions['normalize_volume'];
            $needsConversion = $isEdited || pathinfo($downloadedFile, PATHINFO_EXTENSION) !== $this->download->format;

            if ($needsConversion) {
                Log::info('Converting audio format', $editOptions);

                $this->download->update(['status_text' => "Converting to {$this->download->format}..."]);

                // Progress is measured against the length of the part being kept
                $duration = max(1, ($editOptions['trim_end'] ?: ($this->download->duration ?: 1)) - $editOptions['trim_start']);
                $lastConversionProgress = 0;

                try {
                    $audioConverter->convert(
                        $downloadedFile,
                        $finalFile,
                        $this->download->format,
                        (int) $this->download->quality,
                        function ($sizeKb, $timeStr) use ($duration, &$lastConversionProgress) {
                            // parse timeStr "00:00:10.00" to seconds
                            $parts = explode(':', $timeStr);
                            if (count($parts) === 3) {
                                $seconds = ((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + (float) $parts[2];
                                $percent = min(100, max(0, ($seconds / $duration) * 100));
                                $intPercent = (int) $percent;

                                if ($intPercent > $lastConversionProgress) {
                                    $this->abortIfCancelled();

                                    // Map 0-100 conversion to 65-90 overall progress
                                    $overallProgress = 65 + ($percent * 0.25);

                                    $sizeMb = number_format($sizeKb / 1024, 2);
                                    $this->download->update([
                                        'progress' => (int) $overallProgress,
                                        'conversion_progress' => $intPercent,
                                        'status_text' => "Converting to {$this->download->format}... {$intPercent}% ({$sizeMb} MB)"
                                    ]);
                                    $lastConversionProgress = $intPercent;
                                }
                            }
                        },
                        $editOptions + ['duration' => $this->download->duration]
                    );

                    // Remove temp file after conversion
                    @unlink($downloadedFile);

                    // Trimming/silence removal changes the length: store the real one
                    if ($isEdited) {
                        try {
                            $actual = $audioConverter->getAudioInfo($finalFile)['duration'];
                            if ($actual > 0) {
                                $this->download->update(['duration' => $actual]);
                            }
                        } catch (Exception $e) {
                            // ffprobe is optional; keep the original duration
                        }
                    }
                } catch (DownloadCancelledException $e) {
                    throw $e;
                } catch (Exception $e) {
                    throw new AudioConversionException(
                        'Failed to convert audio: ' . $e->getMessage()
                    );
                }
            } else {
                // Just move the file
                rename($downloadedFile, $finalFile);
            }

            $this->abortIfCancelled();

            $this->download->update([
                'progress' => 90,
                'conversion_progress' => 100,
                'status_text' => 'Applying metadata tags...',
            ]);

            // Step 7: Apply metadata tags
            Log::info('Applying metadata tags');

            try {
                $metadata = [
                    // "D-Sturb - LADY" by "D-Sturb" is tagged as "LADY"
                    'title' => TrackTitle::songTitle($this->download->title, $this->download->artist),
                    'artist' => $this->download->artist,
                    'album' => $this->download->album,
                    'year' => $this->download->year,
                    'genre' => $this->download->genre,
                ];

                $metadataService->tagFile($finalFile, $metadata);

                // Download and attach thumbnail if available (WAV cannot hold cover art)
                if ($this->download->thumbnail_url && $this->download->format !== 'wav') {
                    $this->download->update(['progress' => 92, 'status_text' => 'Adding cover art...']);
                    $thumbnailPath = $tempFile . '_thumb';

                    if ($metadataService->downloadThumbnail($this->download->thumbnail_url, $thumbnailPath)) {
                        $metadataService->attachThumbnail($finalFile, $thumbnailPath);
                    }

                    @unlink($thumbnailPath);
                }
            } catch (Exception $e) {
                // Metadata tagging is not critical, log and continue
                Log::warning('Metadata tagging failed', ['error' => $e->getMessage()]);
            }

            // Step 7b: Lyrics (optional; written last so the cover-art rewrite keeps them)
            if (!$chapters && Setting::fetchLyrics()) {
                $this->download->update(['progress' => 94, 'status_text' => 'Looking for lyrics...']);
                $this->download->update($lyricsService->lookup($this->download));

                if ($this->download->lyrics) {
                    $metadataService->writeLyrics($finalFile, $this->download->lyrics);
                }
            }

            if ($chapters) {
                // Step 8: One file per chapter; this download becomes track 1
                $this->download->update(['progress' => 96, 'status_text' => 'Splitting into ' . count($chapters) . ' tracks...']);
                $chapterSplitService->split($this->download, $finalFile, $chapters, $downloadPath);
                $finalFile = $this->download->file_path;
                $fileFields = [];
            } else {
                $this->download->update(['progress' => 97, 'status_text' => 'Drawing waveform...']);

                // Step 8: Waveform for the player's seek bar (optional: null on failure)
                $fileFields = [
                    'file_path' => $finalFile,
                    'file_size' => filesize($finalFile),
                    'waveform' => $waveformService->generate($finalFile),
                ];
            }

            // Step 9: Mark as completed
            $this->download->update($fileFields + [
                'status' => 'completed',
                'status_text' => $chapters ? 'Completed: split into ' . count($chapters) . ' tracks' : 'Completed',
                'progress' => 100,
                'error_message' => null,
                'completed_at' => now(),
            ]);

            $this->download->syncPlaylistProgress();

            Log::info('Download completed successfully', [
                'download_id' => $this->download->id,
                'file_path' => $finalFile,
            ]);

        } catch (DownloadCancelledException $e) {
            Log::info('Download cancelled', ['download_id' => $this->download->id]);

            $this->cleanUp($tempFile, $finalFile);

            // No-op when the download was deleted rather than cancelled
            Download::whereKey($this->download->id)->update(['status_text' => 'Cancelled']);
        } catch (Throwable $e) {
            Log::error('Download job failed', [
                'download_id' => $this->download->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->cleanUp($tempFile, $finalFile);

            // Keep the download "pending" between attempts so the UI does not show it as
            // failed (and offer a manual retry) while the queue will retry it anyway.
            // The final attempt is handled by failed().
            if ($this->attempts() < $this->tries && !$this->download->isCancelled()) {
                $this->download->update([
                    'status' => 'pending',
                    'error_message' => $e->getMessage(),
                    'status_text' => "Attempt {$this->attempts()} of {$this->tries} failed, retrying...",
                ]);
            }

            // Re-throw to trigger retry mechanism
            throw $e;
        }
    }

    /**
     * Handle a job failure (after the last attempt, a timeout, or a worker crash).
     */
    public function failed(?Throwable $exception): void
    {
        $message = $exception?->getMessage() ?? 'Unknown error';

        Log::error('Download job failed permanently', [
            'download_id' => $this->download->id,
            'error' => $message,
        ]);

        if ($this->download->isCancelled()) {
            return;
        }

        $this->download->update([
            'status' => 'failed',
            'status_text' => 'Failed',
            'error_message' => 'Download failed after ' . $this->attempts() . ' attempt(s): ' . $message,
        ]);

        $this->download->syncPlaylistProgress();
    }

    /**
     * Stop processing when the user cancelled or deleted the download.
     */
    private function abortIfCancelled(): void
    {
        if ($this->download->isCancelled()) {
            throw new DownloadCancelledException("Download {$this->download->id} was cancelled");
        }
    }

    /**
     * Trim / silence options for the converter
     */
    private function editOptions(): array
    {
        $start = (int) ($this->download->trim_start ?? 0);
        $end = $this->download->trim_end ? (int) $this->download->trim_end : 0;

        return [
            'trim_start' => $start,
            'trim_end' => $end > $start ? $end : 0,
            'remove_silence' => (bool) $this->download->remove_silence,
            'normalize_volume' => (bool) $this->download->normalize_volume,
        ];
    }

    /**
     * Chapters are only split for whole videos (a trimmed part has no matching chapters)
     */
    private function wantsChapters(): bool
    {
        return $this->download->split_chapters && !$this->download->trim_start && !$this->download->trim_end;
    }

    private function needsVideoInfo(): bool
    {
        return !$this->hasRealTitle()
            || !$this->download->youtube_id
            || !$this->download->duration
            || !$this->download->thumbnail_url;
    }

    private function hasRealTitle(): bool
    {
        return filled($this->download->title) && $this->download->title !== Download::PLACEHOLDER_TITLE;
    }

    private function minDiskSpaceMb(): int
    {
        return (int) Setting::get('min_disk_space_mb', config('youtube-converter.min_disk_space_mb', 500));
    }

    /**
     * Remove temp files and a partially written output file.
     */
    private function cleanUp(?string $tempFile, ?string $finalFile): void
    {
        if ($tempFile) {
            foreach (glob($tempFile . '*') ?: [] as $file) {
                @unlink($file);
            }
        }

        // Only remove the output if this job created it but never finished
        if ($finalFile && file_exists($finalFile) && $this->download->file_path !== $finalFile) {
            @unlink($finalFile);
        }
    }
}
