<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Download;
use App\Services\YouTubeService;
use App\Services\AudioConverterService;
use App\Services\MetadataService;
use App\Services\DiskSpaceService;
use App\Services\UrlGeneratorService;
use App\Exceptions\InsufficientDiskSpaceException;
use App\Exceptions\YouTubeDownloadException;
use App\Exceptions\AudioConversionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Exception;

class DownloadYoutubeAudioJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels, Batchable;

    public $timeout = 600; // 10 minutes
    public $tries = 3;
    public $backoff = [60, 300, 900]; // Exponential backoff: 1min, 5min, 15min

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Download $download
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        YouTubeService $youtubeService,
        AudioConverterService $audioConverter,
        MetadataService $metadataService,
        DiskSpaceService $diskSpaceService,
        UrlGeneratorService $urlGenerator
    ): void
    {
        try {
            // Mark as processing
            $this->download->markAsProcessing();
            Log::info('Starting download job', ['download_id' => $this->download->id]);

            // Step 1: Check disk space
            $minSpace = (int) \App\Models\Setting::get('min_disk_space_mb', config('youtube-converter.min_disk_space_mb', 500));
            if (!$diskSpaceService->hasEnoughSpace($minSpace)) {
                throw new InsufficientDiskSpaceException(
                    "Not enough disk space available. Required: {$minSpace}MB."
                );
            }

            // Step 2: Extract video info if not already done
            if (!$this->download->youtube_id) {
                $videoInfo = $youtubeService->extractVideoInfo($this->download->youtube_url);
                
                $this->download->update([
                    'youtube_id' => $videoInfo['id'],
                    'title' => $videoInfo['title'],
                    'artist' => $videoInfo['artist'] ?? $this->download->artist,
                    'thumbnail_url' => $videoInfo['thumbnail'],
                    'duration' => $videoInfo['duration'],
                ]);
            }

            $this->download->updateProgress(10);

            // Step 3: Prepare download paths
            $downloadPath = config('youtube-converter.download_path');
            if (!is_dir($downloadPath)) {
                mkdir($downloadPath, 0755, true);
            }

            $tempPath = storage_path('app/temp');
            if (!is_dir($tempPath)) {
                mkdir($tempPath, 0755, true);
            }

            $safeFilename = Str::slug($this->download->title);
            $tempFile = $tempPath . '/' . $safeFilename . '_' . time();
            $finalFile = $downloadPath . '/' . $safeFilename . '.' . $this->download->format;

            // Make filename unique if it already exists
            $counter = 1;
            while (file_exists($finalFile)) {
                $finalFile = $downloadPath . '/' . $safeFilename . '_' . $counter . '.' . $this->download->format;
                $counter++;
            }

            $this->download->updateProgress(20);

            // Step 4: Download audio using yt-dlp
            Log::info('Downloading audio', ['url' => $this->download->youtube_url]);
            
            $lastReportedProgress = 20;
            try {
                $youtubeService->downloadAudio(
                    $this->download->youtube_url,
                    $tempFile,
                    $this->download->format,
                    (int) $this->download->quality,
                    function ($progress, $size = null) use (&$lastReportedProgress) {
                        // yt-dlp progress is 0 to 100
                        // Map it to the 20-50 overall progress range
                        $overallProgress = 20 + ($progress * 0.3);
                        $intProgress = (int) $overallProgress;
                        
                        $sizeText = $size ? " ($size)" : "";
                        $statusText = "Downloading video stream... {$progress}%{$sizeText}";
                        
                        // Only update the database when the integer percentage changes to avoid spamming
                        if ($intProgress > $lastReportedProgress) {
                            $this->download->update([
                                'progress' => $intProgress,
                                'status_text' => $statusText
                            ]);
                            $lastReportedProgress = $intProgress;
                        }
                    }
                );
            } catch (Exception $e) {
                throw new YouTubeDownloadException(
                    'Failed to download audio from YouTube: ' . $e->getMessage()
                );
            }

            $this->download->updateProgress(50);
            $this->download->update(['status_text' => 'Download complete. Preparing for conversion...']);

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

            $this->download->updateProgress(60);

            // Step 6: Convert if necessary (since we removed -x from yt-dlp, it will be necessary)
            $needsConversion = pathinfo($downloadedFile, PATHINFO_EXTENSION) !== $this->download->format;
            
            if ($needsConversion) {
                Log::info('Converting audio format');
                
                $duration = $this->download->duration ?: 1; // avoid division by zero
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
                                $seconds = ($parts[0] * 3600) + ($parts[1] * 60) + (float)$parts[2];
                                $percent = min(100, max(0, ($seconds / $duration) * 100));
                                $intPercent = (int) $percent;
                                
                                if ($intPercent > $lastConversionProgress) {
                                    // Map 0-100 conversion to 60-80 overall progress
                                    $overallProgress = 60 + ($percent * 0.2);
                                    
                                    $sizeMb = number_format($sizeKb / 1024, 2);
                                    $this->download->update([
                                        'progress' => (int) $overallProgress,
                                        'conversion_progress' => $intPercent,
                                        'status_text' => "Converting to {$this->download->format}... {$intPercent}% ({$sizeMb} MB)"
                                    ]);
                                    $lastConversionProgress = $intPercent;
                                }
                            }
                        }
                    );
                    
                    // Remove temp file after conversion
                    @unlink($downloadedFile);
                } catch (Exception $e) {
                    throw new AudioConversionException(
                        'Failed to convert audio: ' . $e->getMessage()
                    );
                }
            } else {
                // Just move the file
                rename($downloadedFile, $finalFile);
            }

            $this->download->updateProgress(80);

            // Step 7: Apply metadata tags
            Log::info('Applying metadata tags');
            
            try {
                $metadata = [
                    'title' => $this->download->title,
                    'artist' => $this->download->artist,
                    'album' => $this->download->album,
                ];

                $metadataService->tagFile($finalFile, $metadata);

                // Download and attach thumbnail if available
                if ($this->download->thumbnail_url) {
                    $thumbnailPath = $tempPath . '/' . $safeFilename . '_thumb.jpg';
                    
                    if ($metadataService->downloadThumbnail($this->download->thumbnail_url, $thumbnailPath)) {
                        $metadataService->attachThumbnail($finalFile, $thumbnailPath);
                        @unlink($thumbnailPath);
                    }
                }
            } catch (Exception $e) {
                // Metadata tagging is not critical, log and continue
                Log::warning('Metadata tagging failed', ['error' => $e->getMessage()]);
            }

            $this->download->updateProgress(90);

            // Step 8: Get file info and generate download URL
            $fileSize = filesize($finalFile);
            $downloadUrl = $urlGenerator->generateDownloadUrl($this->download);

            // Step 9: Mark as completed
            $this->download->update([
                'status' => 'completed',
                'file_path' => $finalFile,
                'file_size' => $fileSize,
                'download_url' => $downloadUrl,
                'progress' => 100,
                'completed_at' => now(),
            ]);

            Log::info('Download completed successfully', [
                'download_id' => $this->download->id,
                'file_path' => $finalFile,
            ]);

        } catch (Exception $e) {
            Log::error('Download job failed', [
                'download_id' => $this->download->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->download->markAsFailed($e->getMessage());

            // Clean up any temp files
            if (isset($tempFile)) {
                foreach (glob($tempFile . '*') as $file) {
                    @unlink($file);
                }
            }

            // Re-throw to trigger retry mechanism
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Exception $exception): void
    {
        Log::error('Download job failed permanently', [
            'download_id' => $this->download->id,
            'error' => $exception->getMessage(),
        ]);

        $this->download->markAsFailed(
            'Download failed after ' . $this->tries . ' attempts: ' . $exception->getMessage()
        );
    }
}
