<?php

namespace Tests\Feature\Api;

use App\Models\Download;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\UsesTemporaryDownloadDirectory;
use Tests\TestCase;

class SettingsAndDashboardApiTest extends TestCase
{
    use RefreshDatabase, UsesTemporaryDownloadDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDownloadDirectory();
    }

    private function validSettings(array $overrides = []): array
    {
        return array_merge([
            'download_path' => $this->downloadDir . '/music',
            'max_playlist_size' => 25,
            'rate_limit_seconds' => 4,
            'min_disk_space_mb' => 200,
        ], $overrides);
    }

    public function test_settings_fall_back_to_config_defaults(): void
    {
        Setting::query()->delete();
        config(['youtube-converter.max_playlist_size' => 50]);

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('download_path', $this->downloadDir)
            ->assertJsonPath('max_playlist_size', 50)
            ->assertJsonPath('ytdlp_cookies_browser', '');
    }

    public function test_settings_can_be_updated_and_the_download_folder_is_created(): void
    {
        $this->putJson('/api/settings', $this->validSettings())->assertOk();

        $this->assertDirectoryExists($this->downloadDir . '/music');
        $this->assertSame($this->downloadDir . '/music', Setting::downloadPath());
        $this->assertSame('25', Setting::get('max_playlist_size'));
        $this->assertSame('4', Setting::get('rate_limit_seconds'));
    }

    public function test_relative_download_paths_are_anchored_to_the_project_root(): void
    {
        $this->putJson('/api/settings', $this->validSettings(['download_path' => 'storage/framework/testing/relative-dl']))->assertOk();

        $this->assertSame(base_path('storage/framework/testing/relative-dl'), Setting::downloadPath());
        $this->assertDirectoryExists(base_path('storage/framework/testing/relative-dl'));
        rmdir(base_path('storage/framework/testing/relative-dl'));
    }

    public function test_youtube_cookie_settings_can_be_saved_and_cleared(): void
    {
        $this->putJson('/api/settings', $this->validSettings(['ytdlp_cookies_browser' => 'firefox']))->assertOk();
        $this->getJson('/api/settings')
            ->assertJsonPath('ytdlp_cookies_browser', 'firefox')
            ->assertJsonPath('cookie_browsers.0', 'firefox');

        $cookies = $this->makeAudioFile('cookies.txt', '# Netscape HTTP Cookie File');
        $this->putJson('/api/settings', $this->validSettings(['ytdlp_cookies_browser' => null, 'ytdlp_cookies_file' => $cookies]))->assertOk();
        $this->assertSame('', Setting::get('ytdlp_cookies_browser'));
        $this->assertSame($cookies, Setting::get('ytdlp_cookies_file'));

        $this->putJson('/api/settings', $this->validSettings(['ytdlp_cookies_file' => $this->downloadDir . '/missing.txt']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ytdlp_cookies_file');
    }

    public function test_youtube_access_can_be_checked(): void
    {
        Process::fake(['*yt-dlp*' => "jNQXAC9IVRw
"]);

        $this->getJson('/api/settings/test-youtube')->assertOk()->assertJsonPath('ok', true);
    }

    public function test_settings_are_validated(): void
    {
        $this->putJson('/api/settings', [
            'max_playlist_size' => 0,
            'rate_limit_seconds' => -1,
            'min_disk_space_mb' => 10,
            'ytdlp_cookies_browser' => 'netscape-navigator',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'download_path', 'max_playlist_size', 'rate_limit_seconds', 'min_disk_space_mb', 'ytdlp_cookies_browser',
        ]);
    }

    public function test_a_download_path_that_cannot_be_created_is_rejected(): void
    {
        // A regular file is in the way, so the directory can never be created
        $blocker = $this->makeAudioFile('not-a-directory');

        $this->putJson('/api/settings', $this->validSettings(['download_path' => $blocker . '/music']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('download_path');
    }

    public function test_disk_space_reports_the_configured_folder(): void
    {
        $response = $this->getJson('/api/settings/disk-space')
            ->assertOk()
            ->assertJsonPath('path', $this->downloadDir);

        $this->assertGreaterThan(0, $response->json('free_mb'));
        $this->assertGreaterThanOrEqual($response->json('free_mb'), $response->json('total_mb'));
    }

    public function test_dependency_check_reports_installed_tools(): void
    {
        // Leading * because Symfony expands the program to its full path on Windows
        Process::fake([
            '*yt-dlp*' => "2026.08.19\n",
            '*ffmpeg*' => "ffmpeg version 9.0.1 Copyright (c) 2000-2026\n",
        ]);

        $this->getJson('/api/settings/test-dependencies')->assertExactJson([
            'yt_dlp' => ['installed' => true, 'version' => '2026.08.19'],
            'ffmpeg' => ['installed' => true, 'version' => '9.0.1'],
        ]);

        // Array commands: no shell, so PATH lookups behave the same in web and CLI
        Process::assertRan(fn (PendingProcess $process) => $process->command === ['ffmpeg', '-version']);
    }

    public function test_dependency_check_reports_missing_tools(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);

        $this->getJson('/api/settings/test-dependencies')
            ->assertJsonPath('yt_dlp.installed', false)
            ->assertJsonPath('ffmpeg.installed', false);
    }

    public function test_dashboard_stats(): void
    {
        Download::factory()->completed()->create(['file_size' => 1024 * 1024, 'title' => 'Newest', 'completed_at' => now()]);
        Download::factory()->completed()->create(['file_size' => 1024 * 1024, 'completed_at' => now()->subDay()]);
        Download::factory()->failed()->create();
        DB::table('failed_jobs')->insert([
            'uuid' => 'x', 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'boom', 'failed_at' => now(),
        ]);

        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('total_downloads', 2)
            ->assertJsonPath('total_storage', '2 MB')
            ->assertJsonPath('queue_pending', 0)
            ->assertJsonPath('jobs_failed', 1)
            ->assertJsonPath('downloads_failed', 1)
            ->assertJsonPath('recent_downloads.0.title', 'Newest');
    }

    public function test_the_spa_and_health_check_load(): void
    {
        $this->withoutVite();

        $this->get('/')->assertOk();
        $this->get('/history')->assertOk();
        $this->get('/up')->assertOk();
    }
}
