<?php

namespace Tests\Feature\Api;

use App\Jobs\DownloadYoutubeAudioJob;
use App\Models\Download;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\UsesTemporaryDownloadDirectory;
use Tests\TestCase;

/**
 * Search, WAV, trimming, clean titles, yt-dlp updates and setup checks.
 */
class NewFeaturesApiTest extends TestCase
{
    use RefreshDatabase, UsesTemporaryDownloadDirectory;

    private const URL = 'https://www.youtube.com/watch?v=TpHlTBRLHgs';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDownloadDirectory();
    }

    public function test_search_returns_videos_only_and_is_cached(): void
    {
        Process::fake(['*' => json_encode(['entries' => [
            ['id' => 'TpHlTBRLHgs', 'title' => 'D-Sturb - LADY (Official Music Video)', 'channel' => 'Q-dance Music', 'duration' => 177, 'view_count' => 138530],
            ['id' => 'UCxxxxxxxxxxxxxxxxxxxxxx', 'title' => 'A channel'],
            ['id' => 'LiveStream1', 'title' => 'Live now', 'live_status' => 'is_live'],
        ]])]);

        $this->getJson('/api/search?q=d-sturb lady')
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.url', self::URL)
            ->assertJsonPath('results.0.thumbnail', 'https://i.ytimg.com/vi/TpHlTBRLHgs/mqdefault.jpg')
            ->assertJsonPath('results.0.channel', 'Q-dance Music');

        $this->getJson('/api/search?q=D-Sturb Lady')->assertOk();

        Process::assertRanTimes(fn (PendingProcess $process) => in_array('ytsearch12:d-sturb lady', $process->command, true), 1);
    }

    public function test_search_validates_and_reports_errors(): void
    {
        $this->getJson('/api/search?q=a')->assertUnprocessable();

        Process::fake(['*' => Process::result(errorOutput: 'ERROR: HTTP Error 429', exitCode: 1)]);
        $this->getJson('/api/search?q=something')->assertStatus(502)->assertJsonPath('results', []);
    }

    public function test_fetch_info_suggests_a_clean_title_and_artist(): void
    {
        Process::fake(['*' => json_encode(['id' => 'TpHlTBRLHgs', 'title' => 'D-Sturb - LADY (Official Music Video)', 'uploader' => 'Q-dance Music', 'upload_date' => '20190412'])]);

        $this->postJson('/api/downloads/fetch-info', ['youtube_url' => self::URL])
            ->assertOk()
            ->assertJsonPath('suggested.title', 'D-Sturb - LADY')
            ->assertJsonPath('suggested.artist', 'D-Sturb')
            ->assertJsonPath('video.year', 2019);

        Setting::set('clean_titles', '0');
        $this->postJson('/api/downloads/fetch-info', ['youtube_url' => self::URL])
            ->assertJsonPath('suggested.title', 'D-Sturb - LADY (Official Music Video)');
    }

    public function test_wav_and_trim_options_are_stored(): void
    {
        Queue::fake();

        $this->postJson('/api/downloads', [
            'youtube_url' => self::URL,
            'format' => 'wav',
            'quality' => '320',
            'year' => 2019,
            'trim_start' => 5,
            'trim_end' => 170,
            'remove_silence' => true,
        ])->assertCreated()
            ->assertJsonPath('download.format', 'wav')
            ->assertJsonPath('download.trim_start', 5)
            ->assertJsonPath('download.trim_end', 170)
            ->assertJsonPath('download.remove_silence', true);

        $this->postJson('/api/downloads', ['youtube_url' => self::URL, 'format' => 'ogg', 'quality' => '320'])
            ->assertJsonValidationErrors('format');

        $this->postJson('/api/downloads', ['youtube_url' => self::URL, 'format' => 'mp3', 'quality' => '320', 'trim_start' => 60, 'trim_end' => 30])
            ->assertJsonValidationErrors('trim_end');
    }

    public function test_trimmed_requests_never_reuse_an_untrimmed_file(): void
    {
        Queue::fake();
        Download::factory()->completed($this->makeAudioFile())->create(['youtube_id' => 'TpHlTBRLHgs', 'format' => 'mp3', 'quality' => '320']);

        $this->postJson('/api/downloads', ['youtube_url' => self::URL, 'format' => 'mp3', 'quality' => '320', 'trim_start' => 10])
            ->assertCreated();

        Queue::assertPushed(DownloadYoutubeAudioJob::class, 1);
    }

    public function test_wav_files_are_streamed_as_audio_wav(): void
    {
        $download = Download::factory()->completed($this->makeAudioFile('song.wav', 'RIFF'))->create(['format' => 'wav']);

        $this->get("/api/downloads/{$download->id}/stream")->assertOk()->assertHeader('Content-Type', 'audio/wav');
    }

    public function test_clean_titles_setting_can_be_saved(): void
    {
        $this->putJson('/api/settings', [
            'download_path' => $this->downloadDir,
            'max_playlist_size' => 50,
            'min_disk_space_mb' => 500,
            'clean_titles' => false,
        ])->assertOk();

        $this->assertFalse(Setting::cleanTitles());
        $this->getJson('/api/settings')->assertJsonPath('clean_titles', false);
    }

    public function test_yt_dlp_version_reports_available_updates(): void
    {
        Process::fake(['*' => "2026.07.01\n"]);
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => '2026.08.19'])]);

        $this->getJson('/api/settings/yt-dlp')->assertExactJson([
            'installed' => '2026.07.01',
            'latest' => '2026.08.19',
            'update_available' => true,
        ]);
    }

    public function test_yt_dlp_self_update(): void
    {
        $versions = ['2026.07.01', '2026.08.19'];
        Process::fake(function (PendingProcess $process) use (&$versions) {
            if (in_array('--version', $process->command, true)) {
                return array_shift($versions) . "\n";
            }

            return Process::result(output: 'Updated yt-dlp to stable@2026.08.19');
        });

        $this->postJson('/api/settings/yt-dlp/update')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('updated', true)
            ->assertJsonPath('version', '2026.08.19');
    }

    public function test_yt_dlp_installed_with_pip_gets_the_command_to_run(): void
    {
        Process::fake(function (PendingProcess $process) {
            if (in_array('--version', $process->command, true)) {
                return "2026.07.01\n";
            }

            return Process::result(errorOutput: 'ERROR: You installed yt-dlp with pip or using the wheel from PyPi; Use that to update', exitCode: 1);
        });

        $this->postJson('/api/settings/yt-dlp/update')
            ->assertJsonPath('ok', false)
            ->assertJsonPath('updated', false)
            ->assertJsonPath('message', 'yt-dlp was installed with a package manager. Update it with python -m pip install -U yt-dlp.');
    }

    public function test_setup_checks_list_what_is_missing(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => '2026.08.19'])]);
        Process::fake([
            '*yt-dlp*' => "2026.08.19\n",
            '*ffmpeg*' => "ffmpeg version 9.0.1 Copyright\n",
            '*deno*' => Process::result(exitCode: 1),
        ]);

        $response = $this->getJson('/api/settings/setup-checks')->assertOk();
        $checks = collect($response->json('checks'))->keyBy('key');

        $this->assertTrue($checks['yt_dlp']['ok']);
        $this->assertTrue($checks['ffmpeg']['ok']);
        $this->assertFalse($checks['deno']['ok']);
        $this->assertFalse($checks['deno']['required']);
        $this->assertTrue($checks['download_folder']['ok']);
        // No worker in tests, and it is required
        $this->assertFalse($checks['queue_worker']['ok']);
        $response->assertJsonPath('ready', false);
    }
}
