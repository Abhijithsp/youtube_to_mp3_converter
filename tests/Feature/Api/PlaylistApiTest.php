<?php

namespace Tests\Feature\Api;

use App\Jobs\DownloadYoutubeAudioJob;
use App\Jobs\ProcessPlaylistJob;
use App\Models\Download;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Setting;
use App\Services\YouTubeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlaylistApiTest extends TestCase
{
    use RefreshDatabase;

    private const PLAYLIST_URL = 'https://www.youtube.com/playlist?list=PLhardstyle';

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'url' => self::PLAYLIST_URL,
            'title' => 'Hardstyle',
            'format' => 'mp3',
            'quality' => '320',
            'items' => [
                ['id' => 'TpHlTBRLHgs', 'title' => 'LADY', 'url' => 'https://www.youtube.com/watch?v=TpHlTBRLHgs'],
                ['id' => 'MnH03XLqeRs', 'title' => 'Nothing Like The Oldschool', 'url' => 'https://www.youtube.com/watch?v=MnH03XLqeRs'],
            ],
        ], $overrides);
    }

    public function test_fetch_info_returns_the_playlist(): void
    {
        Process::fake(['*' => json_encode([
            'title' => 'Hardstyle',
            'entries' => [['id' => 'TpHlTBRLHgs', 'title' => 'LADY', 'url' => 'https://www.youtube.com/watch?v=TpHlTBRLHgs']],
        ])]);

        $this->postJson('/api/playlists/fetch-info', ['url' => self::PLAYLIST_URL])
            ->assertOk()
            ->assertJsonPath('title', 'Hardstyle')
            ->assertJsonPath('total', 1);
    }

    public function test_fetch_info_reports_errors(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'This playlist does not exist', exitCode: 1)]);

        $this->postJson('/api/playlists/fetch-info', ['url' => self::PLAYLIST_URL])->assertUnprocessable();
        $this->postJson('/api/playlists/fetch-info', ['url' => 'nope'])->assertUnprocessable();
    }

    public function test_store_creates_the_playlist_items_and_queues_processing(): void
    {
        Queue::fake();

        $this->postJson('/api/playlists', $this->payload())
            ->assertCreated()
            ->assertJsonPath('playlist.youtube_playlist_id', 'PLhardstyle')
            ->assertJsonPath('playlist.total_videos', 2)
            ->assertJsonCount(2, 'playlist.items');

        Queue::assertPushed(ProcessPlaylistJob::class, fn ($job) => $job->format === 'mp3' && $job->quality === 320);
    }

    public function test_importing_the_same_playlist_again_replaces_its_items(): void
    {
        Queue::fake();

        $this->postJson('/api/playlists', $this->payload())->assertCreated();

        // Used to fail with a unique-constraint violation on youtube_playlist_id
        $this->postJson('/api/playlists', $this->payload([
            'items' => [['id' => 'TpHlTBRLHgs', 'title' => 'LADY', 'url' => 'https://www.youtube.com/watch?v=TpHlTBRLHgs']],
        ]))->assertCreated()->assertJsonPath('playlist.total_videos', 1);

        $this->assertDatabaseCount('playlists', 1);
        $this->assertDatabaseCount('playlist_items', 1);
    }

    public function test_store_accepts_channel_urls_without_a_list_id(): void
    {
        Queue::fake();

        $this->postJson('/api/playlists', $this->payload(['url' => 'https://www.youtube.com/@Qdance/videos']))
            ->assertCreated();

        $this->assertStringStartsWith('url-', Playlist::first()->youtube_playlist_id);
    }

    public function test_store_enforces_the_maximum_playlist_size(): void
    {
        Queue::fake();
        Setting::set('max_playlist_size', 1);

        $this->postJson('/api/playlists', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Playlist size exceeds limit of 1 videos.');

        $this->assertDatabaseCount('playlists', 0);
        Queue::assertNothingPushed();
    }

    public function test_store_validates_input(): void
    {
        $this->postJson('/api/playlists', ['url' => 'x', 'items' => [['url' => 'bad']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['url', 'title', 'format', 'quality', 'items.0.url', 'items.0.id']);
    }

    public function test_processing_job_creates_valid_downloads_for_each_item(): void
    {
        Queue::fake();
        Setting::set('rate_limit_seconds', 5);

        $playlist = Playlist::factory()->create(['total_videos' => 2]);
        PlaylistItem::factory()->for($playlist)->create(['youtube_url' => 'https://www.youtube.com/watch?v=TpHlTBRLHgs', 'position' => 1]);
        PlaylistItem::factory()->for($playlist)->create(['youtube_url' => 'https://www.youtube.com/watch?v=MnH03XLqeRs', 'position' => 2]);

        // Used to fail: downloads were created without the required youtube_id/title columns
        (new ProcessPlaylistJob($playlist, 'flac', 256))->handle(new YouTubeService());

        $this->assertSame('processing', $playlist->fresh()->status);
        $this->assertSame(
            ['TpHlTBRLHgs', 'MnH03XLqeRs'],
            Download::orderBy('id')->pluck('youtube_id')->all()
        );
        $this->assertDatabaseHas('downloads', ['title' => Download::PLACEHOLDER_TITLE, 'format' => 'flac', 'quality' => '256']);
        $this->assertSame(0, PlaylistItem::whereNull('download_id')->count());

        Queue::assertPushed(DownloadYoutubeAudioJob::class, 2);
    }

    public function test_processing_job_reuses_songs_already_downloaded(): void
    {
        Queue::fake();
        $path = storage_path('framework/testing/playlist-' . uniqid() . '.mp3');
        file_put_contents($path, 'fake-audio-bytes');
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        $existing = Download::factory()->completed($path)->create(['youtube_id' => 'TpHlTBRLHgs', 'format' => 'mp3', 'quality' => '320']);

        $playlist = Playlist::factory()->create(['total_videos' => 2]);
        $reused = PlaylistItem::factory()->for($playlist)->create(['youtube_url' => 'https://www.youtube.com/watch?v=TpHlTBRLHgs', 'position' => 1]);
        PlaylistItem::factory()->for($playlist)->create(['youtube_url' => 'https://www.youtube.com/watch?v=MnH03XLqeRs', 'position' => 2]);

        // Used to download the song again, saved as "Title (1).mp3"
        (new ProcessPlaylistJob($playlist, 'mp3', 320))->handle(new YouTubeService());

        $this->assertSame($existing->id, $reused->fresh()->download_id);
        $this->assertDatabaseCount('downloads', 2);
        $this->assertSame(1, $playlist->fresh()->processed_videos);
        Queue::assertPushed(DownloadYoutubeAudioJob::class, 1);
        Queue::assertPushed(DownloadYoutubeAudioJob::class, fn ($job) => $job->download->youtube_id === 'MnH03XLqeRs');
    }

    public function test_playlist_completes_when_every_song_was_already_downloaded(): void
    {
        Queue::fake();
        $path = storage_path('framework/testing/playlist-' . uniqid() . '.mp3');
        file_put_contents($path, 'fake-audio-bytes');
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        Download::factory()->completed($path)->create(['youtube_id' => 'TpHlTBRLHgs', 'format' => 'mp3', 'quality' => '320']);
        $playlist = Playlist::factory()->create(['total_videos' => 1]);
        PlaylistItem::factory()->for($playlist)->create(['youtube_url' => 'https://www.youtube.com/watch?v=TpHlTBRLHgs', 'position' => 1]);

        (new ProcessPlaylistJob($playlist, 'mp3', 320))->handle(new YouTubeService());

        $this->assertSame('completed', $playlist->fresh()->status);
        Queue::assertNotPushed(DownloadYoutubeAudioJob::class);
    }

    public function test_index_show_and_destroy(): void
    {
        $playlist = Playlist::factory()->create(['title' => 'Hardstyle']);
        $download = Download::factory()->completed()->create();
        PlaylistItem::factory()->for($playlist)->create(['download_id' => $download->id]);

        $this->getJson('/api/playlists')->assertOk()->assertJsonPath('data.0.items_count', 1);

        $this->getJson("/api/playlists/{$playlist->id}")
            ->assertOk()
            ->assertJsonPath('title', 'Hardstyle')
            ->assertJsonPath('items.0.download.id', $download->id);

        $this->deleteJson("/api/playlists/{$playlist->id}")->assertOk();

        $this->assertModelMissing($playlist);
        $this->assertDatabaseCount('playlist_items', 0);
        // Finished downloads stay in the history
        $this->assertModelExists($download);
    }
}
