<?php

namespace Tests\Feature\Api;

use App\Models\Download;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesTemporaryDownloadDirectory;
use Tests\TestCase;

class HistoryApiTest extends TestCase
{
    use RefreshDatabase, UsesTemporaryDownloadDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDownloadDirectory();
    }

    public function test_history_lists_only_completed_downloads(): void
    {
        Download::factory()->completed()->create(['title' => 'LADY']);
        Download::factory()->failed()->create();
        Download::factory()->processing()->create();

        $this->getJson('/api/history')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.title', 'LADY');
    }

    public function test_history_search_format_filter_and_sorting(): void
    {
        Download::factory()->completed()->create(['title' => 'A song', 'format' => 'mp3', 'file_size' => 300]);
        Download::factory()->completed()->create(['title' => 'B song', 'format' => 'flac', 'file_size' => 900]);
        Download::factory()->completed()->create(['title' => 'Other', 'artist' => 'D-Sturb', 'format' => 'mp3', 'file_size' => 100]);

        $this->getJson('/api/history?search=song')->assertJsonPath('total', 2);
        $this->getJson('/api/history?search=sturb')->assertJsonPath('data.0.title', 'Other');
        $this->getJson('/api/history?format=flac')->assertJsonPath('total', 1);
        $this->getJson('/api/history?sort_by=file_size&sort_dir=asc')->assertJsonPath('data.0.title', 'Other');
        $this->getJson('/api/history?sort_by=title&sort_dir=desc')->assertJsonPath('data.0.title', 'Other');
        // Unknown sort columns fall back to completed_at instead of reaching SQL
        $this->getJson('/api/history?sort_by=id;drop table downloads')->assertOk();
        $this->getJson('/api/history?per_page=99999')->assertJsonPath('per_page', 100);
    }

    public function test_history_entries_link_to_the_stream_endpoint(): void
    {
        $download = Download::factory()->completed($this->makeAudioFile())->create();

        $this->getJson('/api/history')
            ->assertJsonPath('data.0.download_url', route('downloads.stream', $download));
    }

    public function test_clearing_history_keeps_files_by_default(): void
    {
        $path = $this->makeAudioFile();
        Download::factory()->completed($path)->create();
        $pending = Download::factory()->create();

        $this->deleteJson('/api/history')->assertOk();

        $this->assertDatabaseCount('downloads', 1);
        $this->assertModelExists($pending);
        $this->assertFileExists($path);
    }

    public function test_clearing_history_with_keep_files_false_deletes_files(): void
    {
        $path = $this->makeAudioFile();
        Download::factory()->completed($path)->create();

        // The UI sends this as a query string, so the value arrives as the string "false"
        $this->deleteJson('/api/history?keep_files=false')->assertOk();

        $this->assertDatabaseCount('downloads', 0);
        $this->assertFileDoesNotExist($path);
    }
}
