<?php

namespace App\Jobs;

use App\Exceptions\AudioConversionException;
use App\Exceptions\DownloadCancelledException;
use App\Exceptions\InsufficientDiskSpaceException;
use App\Models\Download;
use App\Models\Setting;
use App\Services\AudioConverterService;
use App\Services\DiskSpaceService;
use App\Services\LocalFileService;
use App\Services\LyricsService;
use App\Services\MetadataService;
use App\Services\WaveformService;
use App\Support\FileName;
use App\Support\TrackTitle;
use App\Support\UploadStore;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Converts a video or audio file the user added (uploaded from the browser, or a file
 * on this computer) into MP3/FLAC/WAV. Same steps as a YouTube download, minus yt-dlp;
 * the result lands in the download folder and History like any download.
 *
 * Originals on this computer are only read, never changed or removed here.
 */
class ConvertLocalFileJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    // A file conversion fails the same way every time, so it is not retried by itself
    public $tries = 1;

    // Long recordings (films, DJ sets) take a while to encode
    public $timeout = 3600;

    public $deleteWhenMissingModels = true;

    public function __construct(
        public Download $download
    ) {}

    public function handle(
        LocalFileService $localFiles,
        AudioConverterService $audioConverter,
        MetadataService $metadataService,
        DiskSpaceService $diskSpaceService,
        WaveformService $waveformService,
        LyricsService $lyricsService,
        UploadStore $uploads
    ): void {
        $finalFile = null;
        $source = $this->download->source_path;

        try {
            $this->abortIfCancelled();

            $this->download->update([
                'status' => 'processing',
                'progress' => 0,
                'conversion_progress' => 0,
                'error_message' => null,
                'status_text' => 'Reading file...',
            ]);
            Log::info('Starting file conversion', ['download_id' => $this->download->id, 'source' => $this->download->source]);

            // Step 1: Check the file (it may have been moved since it was added)
            $info = $localFiles->inspect((string) $source, $this->download->source_name);
            if ($info['error']) {
                throw new AudioConversionException($this->download->source === Download::SOURCE_UPLOAD && !is_file((string) $source)
                    ? 'The uploaded file is gone (uploads are kept for a day). Add the file again.'
                    : $info['error']);
            }

            $this->download->update(['duration' => $info['duration'] ?: null, 'original_file_size' => $info['size']]);
            $this->download->updateProgress(2);

            // Step 2: Disk space (configured reserve + estimated size of the result)
            $estimatedMb = $info['duration']
                ? $diskSpaceService->estimateFileSize($info['duration'], match ($this->download->format) {
                    'flac' => 1000,
                    'wav' => 2304,
                    default => (int) $this->download->quality,
                })
                : 0;

            if (!$diskSpaceService->hasEnoughSpace($estimatedMb)) {
                throw new InsufficientDiskSpaceException("Not enough disk space available for this file (~{$estimatedMb}MB).");
            }

            // Step 3: Output path, named after the title like YouTube downloads
            $downloadPath = rtrim(Setting::downloadPath(), '/\\');
            if (!is_dir($downloadPath)) {
                mkdir($downloadPath, 0755, true);
            }

            $safeFilename = FileName::fromTitle($this->download->title, 'file-' . $this->download->id);
            $finalFile = $downloadPath . '/' . $safeFilename . '.' . $this->download->format;

            // Never overwrite anything, including the original when it sits in the download folder
            $counter = 1;
            while (file_exists($finalFile)) {
                $finalFile = $downloadPath . '/' . $safeFilename . ' (' . $counter . ').' . $this->download->format;
                $counter++;
            }

            // Step 4: Convert (most of the wait: 5-85% of the bar)
            $editOptions = $this->editOptions();
            $duration = max(1, ($editOptions['trim_end'] ?: ($info['duration'] ?: 1)) - $editOptions['trim_start']);
            $lastConversionProgress = -1;

            $this->download->update(['progress' => 5, 'status_text' => "Converting to {$this->download->format}..."]);

            try {
                $audioConverter->convert(
                    $source,
                    $finalFile,
                    $this->download->format,
                    (int) $this->download->quality,
                    function ($sizeKb, $timeStr) use ($duration, &$lastConversionProgress) {
                        $parts = explode(':', $timeStr);
                        if (count($parts) !== 3) {
                            return;
                        }

                        $seconds = ((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + (float) $parts[2];
                        $percent = (int) min(100, max(0, ($seconds / $duration) * 100));

                        if ($percent > $lastConversionProgress) {
                            $this->abortIfCancelled();

                            $sizeMb = number_format($sizeKb / 1024, 2);
                            $this->download->update([
                                'progress' => (int) (5 + $percent * 0.8),
                                'conversion_progress' => $percent,
                                'status_text' => "Converting to {$this->download->format}... {$percent}% ({$sizeMb} MB)",
                            ]);
                            $lastConversionProgress = $percent;
                        }
                    },
                    $editOptions + ['duration' => $info['duration'], 'timeout' => $this->timeout]
                );
            } catch (DownloadCancelledException $e) {
                throw $e;
            } catch (Exception $e) {
                throw new AudioConversionException('Failed to convert the file: ' . $e->getMessage());
            }

            // Trimming/silence removal changes the length: store the real one
            try {
                $actual = $audioConverter->getAudioInfo($finalFile)['duration'];
                if ($actual > 0) {
                    $this->download->update(['duration' => $actual]);
                }
            } catch (Exception $e) {
                // Keep the length of the original
            }

            $this->abortIfCancelled();

            // Step 5: Tags
            $this->download->update(['progress' => 88, 'conversion_progress' => 100, 'status_text' => 'Applying metadata tags...']);

            try {
                $metadataService->tagFile($finalFile, [
                    'title' => TrackTitle::songTitle($this->download->title, $this->download->artist),
                    'artist' => $this->download->artist,
                    'album' => $this->download->album,
                    'year' => $this->download->year,
                    'genre' => $this->download->genre,
                ]);

                // Cover: the file's own artwork, or a frame of the video (WAV cannot hold one)
                if ($info['has_cover']) {
                    $this->download->update(['progress' => 91, 'status_text' => 'Adding cover art...']);
                    $cover = $this->download->coverPath();

                    if ($localFiles->extractCover($source, $cover, $info['duration'])) {
                        $this->download->update(['thumbnail_url' => '/api/downloads/' . $this->download->id . '/cover?v=' . filemtime($cover)]);

                        if ($this->download->format !== 'wav') {
                            $metadataService->attachThumbnail($finalFile, $cover);
                        }
                    }
                }
            } catch (Exception $e) {
                Log::warning('Metadata tagging failed', ['error' => $e->getMessage()]);
            }

            // Step 6: Lyrics (optional), written last so the cover-art rewrite keeps them
            if (Setting::fetchLyrics()) {
                $this->download->update(['progress' => 94, 'status_text' => 'Looking for lyrics...']);
                $this->download->update($lyricsService->lookup($this->download));

                if ($this->download->lyrics) {
                    $metadataService->writeLyrics($finalFile, $this->download->lyrics);
                }
            }

            // Step 7: Waveform for the player's seek bar
            $this->download->update(['progress' => 97, 'status_text' => 'Drawing waveform...']);
            $waveform = $waveformService->generate($finalFile);

            $this->download->update([
                'file_path' => $finalFile,
                'file_size' => filesize($finalFile),
                'waveform' => $waveform,
                'status' => 'completed',
                'status_text' => 'Completed',
                'progress' => 100,
                'error_message' => null,
                'completed_at' => now(),
            ]);

            // The uploaded copy is not needed anymore (the user still has their original)
            if ($this->download->source === Download::SOURCE_UPLOAD) {
                $uploads->deleteFor($source);
                $this->download->update(['source_path' => null]);
            }

            Log::info('File conversion completed', ['download_id' => $this->download->id, 'file_path' => $finalFile]);
        } catch (DownloadCancelledException $e) {
            Log::info('File conversion cancelled', ['download_id' => $this->download->id]);

            $this->cleanUp($finalFile);

            // No-op when the download was deleted rather than cancelled
            Download::whereKey($this->download->id)->update(['status_text' => 'Cancelled']);
        } catch (Throwable $e) {
            Log::error('File conversion failed', ['download_id' => $this->download->id, 'error' => $e->getMessage()]);

            $this->cleanUp($finalFile);

            throw $e;
        }
    }

    /**
     * The conversion failed (or the worker gave up on it): show why
     */
    public function failed(?Throwable $exception): void
    {
        if ($this->download->isCancelled()) {
            return;
        }

        $this->download->update([
            'status' => 'failed',
            'status_text' => 'Failed',
            'error_message' => $exception?->getMessage() ?? 'Unknown error',
        ]);
    }

    private function abortIfCancelled(): void
    {
        if ($this->download->isCancelled()) {
            throw new DownloadCancelledException("Download {$this->download->id} was cancelled");
        }
    }

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
     * Remove a half-written result and its cover. The original file is never touched.
     */
    private function cleanUp(?string $finalFile): void
    {
        if ($finalFile && file_exists($finalFile) && $finalFile !== $this->download->source_path && $this->download->file_path !== $finalFile) {
            @unlink($finalFile);
        }

        if (!$this->download->file_path) {
            @unlink($this->download->coverPath());
        }
    }
}
