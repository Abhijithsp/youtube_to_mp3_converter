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

            $queued = 0;

            foreach ($items as $item) {
                $videoId = $youtubeService->extractVideoId($item->youtube_url) ?? '';

                // A song already downloaded in this format/quality is reused, like single and bulk downloads
                if ($videoId !== '' && ($existing = Download::findExisting($videoId, $this->format, (string) $this->quality))) {
                    $item->update(['download_id' => $existing->id]);
                    continue;
                }

                // Create download record. youtube_id and title are required columns; the download job
                // replaces the placeholder title with the real one from yt-dlp
                $download = Download::create([
                    'youtube_url' => $item->youtube_url,
                    'youtube_id' => $videoId,
                    'title' => Download::PLACEHOLDER_TITLE,
                    'format' => $this->format,
                    'quality' => (string) $this->quality,
                    'status' => 'pending',
                ]);

                // Link item to download
                $item->update(['download_id' => $download->id]);

                // Dispatch download job with delay
                DownloadYoutubeAudioJob::dispatch($download)
                    ->delay(now()->addSeconds($queued++ * $delaySeconds));
            }

            // Count the reused songs now; when every song was reused, no download job will
            $this->playlist->syncProgress();

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
