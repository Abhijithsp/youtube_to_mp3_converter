<?php

namespace Tests\Feature\Api;

use App\Jobs\DownloadYoutubeAudioJob;
use App\Models\Download;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\UsesTemporaryDownloadDirectory;
use Tests\TestCase;

class DownloadApiTest extends TestCase
{
    use RefreshDatabase, UsesTemporaryDownloadDirectory;

    private const URL = 'https://www.youtube.com/watch?v=TpHlTBRLHgs';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDownloadDirectory();
        Queue::fake();
    }

    public function test_store_creates_a_pending_download_and_queues_the_job(): void
    {
        $response = $this->postJson('/api/downloads', [
            'youtube_url' => self::URL,
            'format' => 'mp3',
            'quality' => 320,
            'title' => 'D-Sturb - LADY (Official Music Video)',
            'thumbnail_url' => 'https://i.ytimg.com/vi/TpHlTBRLHgs/maxresdefault.jpg',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('download.youtube_id', 'TpHlTBRLHgs')
            ->assertJsonPath('download.status', 'pending')
            ->assertJsonPath('download.quality', '320')
            ->assertJsonPath('download.download_url', null);

        Queue::assertPushed(DownloadYoutubeAudioJob::class, fn ($job) => $job->download->youtube_id === 'TpHlTBRLHgs');
    }

    public function test_store_uses_a_placeholder_title_when_none_is_sent(): void
    {
        $this->postJson('/api/downloads', ['youtube_url' => self::URL, 'format' => 'flac', 'quality' => '192'])
            ->assertCreated()
            ->assertJsonPath('download.title', Download::PLACEHOLDER_TITLE);
    }

    public function test_store_validates_input(): void
    {
        $this->postJson('/api/downloads', ['youtube_url' => 'nope', 'format' => 'ogg', 'quality' => 128])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['youtube_url', 'format', 'quality']);

        $this->postJson('/api/downloads', ['youtube_url' => 'https://vimeo.com/123', 'format' => 'mp3', 'quality' => 320])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Invalid YouTube URL');

        Queue::assertNothingPushed();
    }

    public function test_store_rejects_youtube_urls_without_a_video_id(): void
    {
        // Used to crash with a 500 (youtube_id column is NOT NULL)
        $this->postJson('/api/downloads', [
            'youtube_url' => 'https://www.youtube.com/playlist?list=PL123',
            'format' => 'mp3',
            'quality' => 320,
        ])->assertUnprocessable()->assertJsonPath('success', false);

        $this->assertDatabaseCount('downloads', 0);
    }

    public function test_store_reuses_a_completed_download_with_the_same_format_and_quality(): void
    {
        $existing = Download::factory()->completed($this->makeAudioFile())->create([
            'youtube_id' => 'TpHlTBRLHgs', 'format' => 'mp3', 'quality' => '320',
        ]);

        $this->postJson('/api/downloads', ['youtube_url' => self::URL, 'format' => 'mp3', 'quality' => 320])
            ->assertOk()
            ->assertJsonPath('message', 'This video has already been downloaded')
            ->assertJsonPath('download.id', $existing->id);

        Queue::assertNothingPushed();
    }

    public function test_store_creates_a_new_download_for_another_format_or_a_missing_file(): void
    {
        Download::factory()->completed($this->makeAudioFile())->create([
            'youtube_id' => 'TpHlTBRLHgs', 'format' => 'mp3', 'quality' => '320',
        ]);
        // Completed earlier, but the file has since been deleted from disk
        Download::factory()->completed()->create([
            'youtube_id' => 'TpHlTBRLHgs', 'format' => 'mp3', 'quality' => '192',
        ]);

        $this->postJson('/api/downloads', ['youtube_url' => self::URL, 'format' => 'flac', 'quality' => 320])->assertCreated();
        $this->postJson('/api/downloads', ['youtube_url' => self::URL, 'format' => 'mp3', 'quality' => 192])->assertCreated();

        Queue::assertPushed(DownloadYoutubeAudioJob::class, 2);
    }

    public function test_fetch_info_returns_video_details(): void
    {
        Process::fake(['*' => json_encode(['id' => 'TpHlTBRLHgs', 'title' => 'LADY', 'duration' => 177])]);

        $this->postJson('/api/downloads/fetch-info', ['youtube_url' => self::URL])
            ->assertOk()
            ->assertJsonPath('video.id', 'TpHlTBRLHgs')
            ->assertJsonPath('video.duration', 177);
    }

    public function test_fetch_info_reports_yt_dlp_errors(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'Video unavailable', exitCode: 1)]);

        $this->postJson('/api/downloads/fetch-info', ['youtube_url' => self::URL])
            ->assertStatus(500)
            ->assertJsonPath('success', false);

        $this->postJson('/api/downloads/fetch-info', ['youtube_url' => 'https://example.com'])->assertUnprocessable();
    }

    public function test_index_filters_searches_and_caps_page_size(): void
    {
        Download::factory()->count(3)->create(['status' => 'pending']);
        Download::factory()->failed()->create(['title' => 'Nothing Like The Oldschool']);
        Download::factory()->completed()->create(['title' => 'LADY', 'artist' => 'D-Sturb']);

        $this->getJson('/api/downloads?status=pending')->assertOk()->assertJsonPath('total', 3);
        $this->getJson('/api/downloads?status=pending,failed')->assertOk()->assertJsonPath('total', 4);
        $this->getJson('/api/downloads?search=sturb')->assertOk()->assertJsonPath('data.0.title', 'LADY');
        $this->getJson('/api/downloads?per_page=100000')->assertOk()->assertJsonPath('per_page', 100);
    }

    public function test_show_returns_the_download_with_its_stream_url_once_completed(): void
    {
        $download = Download::factory()->completed($this->makeAudioFile())->create();

        $this->getJson("/api/downloads/{$download->id}")
            ->assertOk()
            ->assertJsonPath('download.download_url', route('downloads.stream', $download));

        $this->getJson('/api/downloads/999')->assertNotFound();
    }

    public function test_destroy_deletes_the_record_and_the_file(): void
    {
        $path = $this->makeAudioFile();
        $download = Download::factory()->completed($path)->create();

        $this->deleteJson("/api/downloads/{$download->id}")->assertOk()->assertJsonPath('success', true);

        $this->assertModelMissing($download);
        $this->assertFileDoesNotExist($path);
    }

    public function test_only_failed_or_cancelled_downloads_can_be_retried(): void
    {
        $failed = Download::factory()->failed()->create(['progress' => 55]);
        $completed = Download::factory()->completed()->create();
        $cancelled = Download::factory()->cancelled()->create();

        $this->postJson("/api/downloads/{$completed->id}/retry")->assertUnprocessable();
        $this->postJson("/api/downloads/{$cancelled->id}/retry")->assertOk()->assertJsonPath('download.status', 'pending');
        Queue::assertPushed(DownloadYoutubeAudioJob::class, 1);

        $this->postJson("/api/downloads/{$failed->id}/retry")
            ->assertOk()
            ->assertJsonPath('download.status', 'pending')
            ->assertJsonPath('download.progress', 0)
            ->assertJsonPath('download.error_message', null);

        Queue::assertPushed(DownloadYoutubeAudioJob::class, 2);
    }

    public function test_pending_and_processing_downloads_can_be_cancelled(): void
    {
        $processing = Download::factory()->processing()->create();
        $completed = Download::factory()->completed()->create();

        $this->postJson("/api/downloads/{$completed->id}/cancel")->assertUnprocessable();

        $this->postJson("/api/downloads/{$processing->id}/cancel")
            ->assertOk()
            ->assertJsonPath('download.status', 'cancelled')
            ->assertJsonPath('download.status_text', 'Cancelled');
    }

    public function test_cancelling_a_playlist_download_updates_the_playlist_progress(): void
    {
        $playlist = Playlist::factory()->create(['total_videos' => 2, 'status' => 'processing']);
        $done = Download::factory()->completed()->create();
        $running = Download::factory()->processing()->create();
        PlaylistItem::factory()->for($playlist)->create(['download_id' => $done->id]);
        PlaylistItem::factory()->for($playlist)->create(['download_id' => $running->id, 'position' => 2]);

        $this->postJson("/api/downloads/{$running->id}/cancel")->assertOk();

        $this->assertSame(2, $playlist->fresh()->processed_videos);
        $this->assertSame('completed', $playlist->fresh()->status);
    }

    public function test_stream_sends_the_file_with_the_right_type_and_a_readable_name(): void
    {
        $download = Download::factory()->completed($this->makeAudioFile('d-sturb-lady.mp3', 'ID3-audio'))->create([
            'title' => 'D-Sturb - LADY (Official Music Video)',
        ]);

        $response = $this->get("/api/downloads/{$download->id}/stream");

        $response->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        $this->assertStringContainsString('D-Sturb - LADY (Official Music Video).mp3', $response->headers->get('Content-Disposition'));
        $this->assertSame('ID3-audio', $response->streamedContent());
    }

    public function test_stream_file_name_strips_characters_invalid_in_file_names(): void
    {
        $download = Download::factory()->completed($this->makeAudioFile())->create([
            'title' => 'AC/DC: Back\\In "Black" | Live?',
        ]);

        $disposition = $this->get("/api/downloads/{$download->id}/stream")->headers->get('Content-Disposition');

        $this->assertStringContainsString('AC DC Back In Black Live.mp3', $disposition);
    }

    public function test_stream_supports_range_requests_for_seeking(): void
    {
        $download = Download::factory()->completed($this->makeAudioFile('song.flac', '0123456789'))->create(['format' => 'flac']);

        $this->get("/api/downloads/{$download->id}/stream", ['Range' => 'bytes=2-5'])
            ->assertStatus(206)
            ->assertHeader('Content-Type', 'audio/flac');
    }

    public function test_stream_returns_404_until_the_file_exists(): void
    {
        $pending = Download::factory()->create();
        $missingFile = Download::factory()->completed()->create();

        $this->getJson("/api/downloads/{$pending->id}/stream")->assertNotFound();
        $this->getJson("/api/downloads/{$missingFile->id}/stream")->assertNotFound();
    }
}
