<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Playlist;
use App\Models\Download;
use App\Services\YouTubeService;
use Illuminate\Support\Facades\Log;
use Exception;

class ProcessPlaylistJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels, Batchable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $playlist,
        public string $format,
        public int $quality
    ) {}

    /**
     * Execute the job.
     */
    public function handle(YouTubeService $youtubeService): void
    {
        try {
            $this->playlist->update(['status' => 'processing']);
            
            // Get selected items that haven't been processed yet
            $items = $this->playlist->selectedItems()
                ->whereNull('download_id')
                ->orderBy('position')
                ->get();
                
            Log::info('Processing playlist', [
                'playlist_id' => $this->playlist->id,
                'items_count' => $items->count()
            ]);

            $delaySeconds = (int) \App\Models\Setting::get('rate_limit_seconds', config('youtube-converter.rate_limit_seconds', 2));

            foreach ($items as $index => $item) {
                // Create download record. youtube_id and title are required columns; the download job
                // replaces the placeholder title with the real one from yt-dlp
                $download = Download::create([
                    'youtube_url' => $item->youtube_url,
                    'youtube_id' => $youtubeService->extractVideoId($item->youtube_url) ?? '',
                    'title' => Download::PLACEHOLDER_TITLE,
                    'format' => $this->format,
                    'quality' => (string) $this->quality,
                    'status' => 'pending',
                ]);

                // Link item to download
                $item->update(['download_id' => $download->id]);

                // Dispatch download job with delay
                DownloadYoutubeAudioJob::dispatch($download)
                    ->delay(now()->addSeconds($index * $delaySeconds));
            }
            
        } catch (Exception $e) {
            Log::error('Playlist processing failed', [
                'playlist_id' => $this->playlist->id,
                'error' => $e->getMessage(),
            ]);
            
            $this->playlist->update(['status' => 'failed']);
            throw $e;
        }
    }
}
