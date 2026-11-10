<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Download;
use App\Models\Setting;
use App\Services\YouTubeService;
use App\Services\DiskSpaceService;
use App\Exceptions\DownloadCancelledException;
use App\Exceptions\InsufficientDiskSpaceException;
use App\Exceptions\YouTubeDownloadException;
use App\Support\FileName;
use App\Support\TrackTitle;
use Illuminate\Support\Facades\Log;
use Exception;
use Throwable;

/**
 * Saves a YouTube video as MP4 at the chosen resolution. Its streams are kept as they
 * are (no re-encoding), so unlike the audio job there is no conversion step, and no
 * tags, lyrics or waveform: rewriting a file of several GB for those is not worth it.
 */
class DownloadYoutubeVideoJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    // Overridden from config/youtube-converter.php in the constructor
    public $timeout = 7200;
    public $tries = 3;
    public $backoff = [60, 300, 900];

    /**
     * Silently drop the job if the download was deleted before it ran.
     */
    public $deleteWhenMissingModels = true;

    public function __construct(
        public Download $download
    ) {
        $this->timeout = max(60, (int) config('youtube-converter.video_job_timeout', $this->timeout));
        $this->tries = max(1, (int) config('youtube-converter.job_tries', $this->tries));
        $this->backoff = config('youtube-converter.job_backoff', $this->backoff);
    }

    public function handle(YouTubeService $youtubeService, DiskSpaceService $diskSpaceService): void
    {
        $tempFile = null;
        $finalFile = null;

        try {
            $this->abortIfCancelled();

            $this->download->update([
                'status' => 'processing',
                'progress' => 0,
                'conversion_progress' => 0,
                'error_message' => null,
                'status_text' => 'Fetching video information...',
            ]);

            // Step 1: Video info. Also gives the size of the chosen resolution
            $videoInfo = $youtubeService->extractVideoInfo($this->download->youtube_url);
            $suggested = TrackTitle::suggest($videoInfo, Setting::cleanTitles());
            $hasTitle = filled($this->download->title) && $this->download->title !== Download::PLACEHOLDER_TITLE;

            $this->download->update(array_filter([
                'title' => $hasTitle ? $this->download->title : $suggested['title'],
                'artist' => $this->download->artist ?: $suggested['artist'],
                'thumbnail_url' => $this->download->thumbnail_url ?: $videoInfo['thumbnail'],
                'duration' => $videoInfo['duration'],
            ], fn ($value) => $value !== null && $value !== ''));

            $this->download->updateProgress(3);

            // Step 2: Disk space. Joining the streams needs room for them and the result at once
            $quality = collect($videoInfo['video_qualities'])->first(fn ($q) => $q['height'] <= $this->download->video_height);
            $estimatedMb = (int) ceil(($quality['size'] ?? 0) * 2 / 1024 / 1024);

            if (!$diskSpaceService->hasEnoughSpace($estimatedMb)) {
                throw new InsufficientDiskSpaceException(
                    'Not enough disk space available. Required: ' . (int) Setting::get('min_disk_space_mb', config('youtube-converter.min_disk_space_mb', 500))
                    . "MB free plus ~{$estimatedMb}MB for this video."
                );
            }

            // Step 3: Paths
            $folder = rtrim(Setting::downloadPath(), '/\\') . '/' . Download::VIDEO_FOLDER;
            if (!is_dir($folder)) {
                mkdir($folder, 0755, true);
            }

            $tempPath = storage_path('app/temp');
            if (!is_dir($tempPath)) {
                mkdir($tempPath, 0755, true);
            }

            // Download ID keeps temp files of concurrent jobs apart
            $tempFile = $tempPath . '/video_' . $this->download->id . '_' . time();
            $finalFile = FileName::reserve(
                $folder,
                FileName::fromTitle($this->download->title, $this->download->youtube_id ?: 'video-' . $this->download->id),
                Download::VIDEO_FORMAT
            );

            $this->download->update(['progress' => 5, 'status_text' => 'Starting download...']);

            // Step 4: Download (5-95% of the bar)
            $lastPercent = -1;

            try {
                $youtubeService->downloadVideo(
                    $this->download->youtube_url,
                    $tempFile,
                    (int) $this->download->video_height,
                    $this->timeout,
                    function ($progress, $size = null) use (&$lastPercent) {
                        $percent = (int) $progress;

                        // One database update per whole percent of the download
                        if ($percent > $lastPercent) {
                            // Throwing here stops the running yt-dlp process
                            $this->abortIfCancelled();

                            $this->download->update([
                                'progress' => (int) (5 + $progress * 0.9),
                                'status_text' => $percent >= 100
                                    ? 'Joining video and audio...'
                                    : "Downloading video... {$percent}%" . ($size ? " ({$size})" : ''),
                            ]);
                            $lastPercent = $percent;
                        }
                    }
                );
            } catch (DownloadCancelledException $e) {
                throw $e;
            } catch (Exception $e) {
                throw new YouTubeDownloadException('Failed to download the video from YouTube: ' . $e->getMessage());
            }

            $this->abortIfCancelled();

            // Step 5: Move into place (replaces the empty file that reserved the name)
            $downloadedFile = $tempFile . '.' . Download::VIDEO_FORMAT;

            if (!is_file($downloadedFile)) {
                throw new YouTubeDownloadException('Downloaded file not found');
            }

            $this->download->update(['progress' => 97, 'status_text' => 'Saving video...']);

            $size = filesize($downloadedFile);

            if (!rename($downloadedFile, $finalFile)) {
                throw new YouTubeDownloadException('Could not move the video to the download folder');
            }

            $this->download->update([
                'file_path' => $finalFile,
                'file_size' => $size,
                'original_file_size' => $size,
                'conversion_progress' => 100,
                'status' => 'completed',
                'status_text' => 'Completed',
                'progress' => 100,
                'error_message' => null,
                'completed_at' => now(),
            ]);

            Log::info('Video download completed', ['download_id' => $this->download->id, 'file_path' => $finalFile]);
        } catch (DownloadCancelledException $e) {
            $this->cleanUp($tempFile, $finalFile);

            // No-op when the download was deleted rather than cancelled
            Download::whereKey($this->download->id)->update(['status_text' => 'Cancelled']);
        } catch (Throwable $e) {
            Log::error('Video download job failed', [
                'download_id' => $this->download->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            $this->cleanUp($tempFile, $finalFile);

            // Stay "pending" between attempts; the final attempt is handled by failed()
            if ($this->attempts() < $this->tries && !$this->download->isCancelled()) {
                $this->download->update([
                    'status' => 'pending',
                    'error_message' => $e->getMessage(),
                    'status_text' => "Attempt {$this->attempts()} of {$this->tries} failed, retrying...",
                ]);
            }

            throw $e;
        }
    }

    /**
     * Handle a job failure (after the last attempt, a timeout, or a worker crash).
     */
    public function failed(?Throwable $exception): void
    {
        if ($this->download->isCancelled()) {
            return;
        }

        $this->download->update([
            'status' => 'failed',
            'status_text' => 'Failed',
            'error_message' => 'Download failed after ' . $this->attempts() . ' attempt(s): ' . ($exception?->getMessage() ?? 'Unknown error'),
        ]);
    }

    private function abortIfCancelled(): void
    {
        if ($this->download->isCancelled()) {
            throw new DownloadCancelledException("Download {$this->download->id} was cancelled");
        }
    }

    /**
     * Remove temp files (both streams and the joined file) and the reserved output name
     */
    private function cleanUp(?string $tempFile, ?string $finalFile): void
    {
        if ($tempFile) {
            foreach (glob($tempFile . '*') ?: [] as $file) {
                @unlink($file);
            }
        }

        if ($finalFile && file_exists($finalFile) && $this->download->file_path !== $finalFile) {
            @unlink($finalFile);
        }
    }
}
