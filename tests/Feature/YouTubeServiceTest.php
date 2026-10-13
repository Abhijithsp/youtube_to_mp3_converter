<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\YouTubeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class YouTubeServiceTest extends TestCase
{
    use RefreshDatabase;

    private YouTubeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['youtube-converter.cookies_from_browser' => null, 'youtube-converter.cookies_file' => null]);
        $this->service = new YouTubeService();
    }

    /**
     * Command as passed to yt-dlp, without the shared network options.
     */
    private static function args(PendingProcess $process): array
    {
        $command = $process->command;
        $retries = array_search('--socket-timeout', $command, true);

        return $command[0] === 'yt-dlp' && $retries !== false ? array_slice($command, $retries + 2) : $command;
    }

    public static function validUrls(): array
    {
        return [
            'watch' => ['https://www.youtube.com/watch?v=TpHlTBRLHgs', 'TpHlTBRLHgs'],
            'watch without www' => ['https://youtube.com/watch?v=MnH03XLqeRs', 'MnH03XLqeRs'],
            'watch with extra params' => ['https://www.youtube.com/watch?v=TpHlTBRLHgs&t=42s&list=PL123', 'TpHlTBRLHgs'],
            'short link' => ['https://youtu.be/MnH03XLqeRs', 'MnH03XLqeRs'],
            'shorts' => ['https://www.youtube.com/shorts/TpHlTBRLHgs', 'TpHlTBRLHgs'],
            'embed' => ['https://www.youtube.com/embed/TpHlTBRLHgs', 'TpHlTBRLHgs'],
            'music' => ['https://music.youtube.com/watch?v=MnH03XLqeRs', 'MnH03XLqeRs'],
            'mobile' => ['https://m.youtube.com/watch?v=TpHlTBRLHgs', 'TpHlTBRLHgs'],
        ];
    }

    #[DataProvider('validUrls')]
    public function test_it_accepts_youtube_urls_and_extracts_the_video_id(string $url, string $id): void
    {
        $this->assertTrue($this->service->validateUrl($url));
        $this->assertSame($id, $this->service->extractVideoId($url));
    }

    public function test_it_rejects_non_youtube_urls(): void
    {
        $this->assertFalse($this->service->validateUrl('https://vimeo.com/12345'));
        $this->assertFalse($this->service->validateUrl('https://example.com/watch?v=TpHlTBRLHgs'));
        $this->assertFalse($this->service->validateUrl('not a url'));
    }

    public function test_playlist_urls_have_no_video_id_but_a_playlist_id(): void
    {
        $url = 'https://www.youtube.com/playlist?list=PLx0sYbCqOb8TBPRdmBHs5Iftvv9TPboYG';

        $this->assertNull($this->service->extractVideoId($url));
        $this->assertSame('PLx0sYbCqOb8TBPRdmBHs5Iftvv9TPboYG', $this->service->extractPlaylistId($url));
        $this->assertNull($this->service->extractPlaylistId('https://www.youtube.com/watch?v=TpHlTBRLHgs'));
    }

    public function test_extract_video_info_maps_yt_dlp_json(): void
    {
        Process::fake([
            '*' => json_encode([
                'id' => 'TpHlTBRLHgs',
                'title' => 'D-Sturb - LADY (Official Music Video)',
                'uploader' => 'Q-dance Music',
                'thumbnail' => 'https://i.ytimg.com/vi/TpHlTBRLHgs/maxresdefault.jpg',
                'duration' => 177.4,
            ]),
        ]);

        $info = $this->service->extractVideoInfo('https://www.youtube.com/watch?v=TpHlTBRLHgs');

        $this->assertSame([
            'id' => 'TpHlTBRLHgs',
            'title' => 'D-Sturb - LADY (Official Music Video)',
            'artist' => 'Q-dance Music',
            'album' => null,
            'thumbnail' => 'https://i.ytimg.com/vi/TpHlTBRLHgs/maxresdefault.jpg',
            'duration' => 177,
            'url' => 'https://www.youtube.com/watch?v=TpHlTBRLHgs',
        ], $info);

        Process::assertRan(fn (PendingProcess $process) => self::args($process) === [
            '--dump-json', '--no-playlist', '--no-warnings', 'https://www.youtube.com/watch?v=TpHlTBRLHgs',
        ]);
    }

    public function test_every_call_uses_retry_and_timeout_options(): void
    {
        Process::fake(['*' => '{"id":"x"}']);

        $this->service->extractVideoInfo('https://youtu.be/TpHlTBRLHgs');

        Process::assertRan(fn (PendingProcess $process) => array_slice($process->command, 0, 9) === [
            'yt-dlp', '--retries', '10', '--fragment-retries', '10', '--extractor-retries', '3', '--socket-timeout', '30',
        ]);
    }

    public function test_extract_video_info_throws_when_yt_dlp_fails(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'ERROR: Video unavailable', exitCode: 1)]);

        $this->expectExceptionMessage('Video unavailable');

        $this->service->extractVideoInfo('https://www.youtube.com/watch?v=xxxxxxxxxxx');
    }

    public function test_bot_checks_get_an_actionable_message(): void
    {
        Process::fake(['*' => Process::result(
            errorOutput: "ERROR: [youtube] TpHlTBRLHgs: Sign in to confirm you\u{2019}re not a bot. Use --cookies-from-browser",
            exitCode: 1,
        )]);

        $this->expectExceptionMessage('Settings → YouTube access');

        $this->service->extractVideoInfo('https://www.youtube.com/watch?v=TpHlTBRLHgs');
    }

    public function test_outdated_yt_dlp_errors_suggest_updating(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'ERROR: unable to download video data: HTTP Error 403: Forbidden', exitCode: 1)]);

        $this->expectExceptionMessage('yt-dlp -U');

        $this->service->downloadAudio('https://youtu.be/TpHlTBRLHgs', storage_path('app/temp/x'));
    }

    public function test_timeouts_mention_the_automatic_retry(): void
    {
        Process::fake(['*' => Process::result(errorOutput: "ERROR: Command 'deno run generate_once.ts' timed out after 15.0 seconds", exitCode: 1)]);

        $this->expectExceptionMessage('retried automatically');

        $this->service->extractVideoInfo('https://youtu.be/TpHlTBRLHgs');
    }

    public function test_extract_playlist_info_reads_title_and_entries_from_one_call(): void
    {
        Process::fake([
            '*' => json_encode([
                'id' => 'PL123',
                'title' => 'Hardstyle Classics',
                'entries' => [
                    ['id' => 'TpHlTBRLHgs', 'title' => 'LADY', 'url' => 'https://www.youtube.com/watch?v=TpHlTBRLHgs', 'duration' => 177],
                    ['id' => 'MnH03XLqeRs', 'title' => 'Nothing Like The Oldschool', 'duration' => 236],
                    ['title' => '[Private video]'],
                ],
            ]),
        ]);

        $info = $this->service->extractPlaylistInfo('https://www.youtube.com/playlist?list=PL123');

        $this->assertSame('PL123', $info['id']);
        $this->assertSame('Hardstyle Classics', $info['title']);
        $this->assertSame(2, $info['total']);
        $this->assertSame('https://www.youtube.com/watch?v=MnH03XLqeRs', $info['videos'][1]['url']);
        $this->assertSame(2, $info['videos'][1]['position']);

        Process::assertRanTimes(fn () => true, 1);
    }

    public function test_download_audio_uses_a_format_fallback_and_passes_the_path_as_one_argument(): void
    {
        Process::fake();

        $output = storage_path('app/temp/download 1 with spaces');
        $this->service->downloadAudio('https://youtu.be/TpHlTBRLHgs', $output);

        Process::assertRan(function (PendingProcess $process) use ($output) {
            $args = self::args($process);

            return in_array($output, $args, true)
                && $args[array_search('-f', $args, true) + 1] === 'bestaudio/best';
        });
    }

    public function test_download_audio_throws_on_failure(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'HTTP Error 403', exitCode: 1)]);

        $this->expectExceptionMessage('Download failed: HTTP Error 403');

        $this->service->downloadAudio('https://youtu.be/TpHlTBRLHgs', storage_path('app/temp/x'));
    }

    public function test_no_cookie_options_by_default(): void
    {
        $this->assertSame([], $this->service->cookieOptions());
    }

    public function test_cookies_from_browser_come_from_env_or_settings(): void
    {
        config(['youtube-converter.cookies_from_browser' => 'firefox']);
        $this->assertSame(['--cookies-from-browser', 'firefox'], $this->service->cookieOptions());

        // The Settings page overrides .env, including clearing it
        Setting::set('ytdlp_cookies_browser', 'edge');
        $this->assertSame(['--cookies-from-browser', 'edge'], $this->service->cookieOptions());

        Setting::set('ytdlp_cookies_browser', '');
        $this->assertSame([], $this->service->cookieOptions());
    }

    public function test_an_existing_cookies_file_takes_precedence_over_the_browser(): void
    {
        $file = storage_path('framework/testing/cookies.txt');
        File::ensureDirectoryExists(dirname($file));
        file_put_contents($file, "# Netscape HTTP Cookie File\n");

        Setting::set('ytdlp_cookies_browser', 'firefox');
        Setting::set('ytdlp_cookies_file', $file);
        $this->assertSame(['--cookies', $file], $this->service->cookieOptions());

        // A missing file is ignored rather than breaking every download
        Setting::set('ytdlp_cookies_file', $file . '.missing');
        $this->assertSame(['--cookies-from-browser', 'firefox'], $this->service->cookieOptions());

        unlink($file);
    }

    public function test_cookies_are_passed_to_every_yt_dlp_call(): void
    {
        Setting::set('ytdlp_cookies_browser', 'firefox');
        Process::fake(['*' => '{"id":"x"}']);

        $this->service->extractVideoInfo('https://youtu.be/TpHlTBRLHgs');

        Process::assertRan(fn (PendingProcess $process) => array_slice(self::args($process), 0, 2) === ['--cookies-from-browser', 'firefox']);
    }

    public function test_it_retries_without_cookies_when_the_browser_cookies_cannot_be_read(): void
    {
        Setting::set('ytdlp_cookies_browser', 'chrome');

        Process::fake(function (PendingProcess $process) {
            return in_array('--cookies-from-browser', $process->command, true)
                ? Process::result(errorOutput: 'ERROR: Could not copy Chrome cookie database. See https://github.com/yt-dlp/yt-dlp/issues/7271', exitCode: 1)
                : Process::result(output: '{"id":"TpHlTBRLHgs","title":"LADY"}');
        });

        $info = $this->service->extractVideoInfo('https://youtu.be/TpHlTBRLHgs');

        $this->assertSame('LADY', $info['title']);
        Process::assertRanTimes(fn () => true, 2);
    }

    public function test_other_errors_are_not_retried_without_cookies(): void
    {
        Setting::set('ytdlp_cookies_browser', 'firefox');
        Process::fake(['*' => Process::result(errorOutput: 'ERROR: Video unavailable', exitCode: 1)]);

        try {
            $this->service->extractVideoInfo('https://youtu.be/TpHlTBRLHgs');
        } catch (\Exception) {
        }

        Process::assertRanTimes(fn () => true, 1);
    }

    public function test_access_check_reports_success_cookie_problems_and_blocks(): void
    {
        Process::fake(['*' => "jNQXAC9IVRw\n"]);
        $this->assertTrue($this->service->testAccess()['ok']);

        Setting::set('ytdlp_cookies_browser', 'brave');
        Process::fake(['*' => Process::result(errorOutput: 'ERROR: Could not copy Chrome cookie database.', exitCode: 1)]);
        $result = $this->service->testAccess();
        $this->assertFalse($result['ok']);
        $this->assertSame('--cookies-from-browser brave', $result['cookies']);
        $this->assertStringContainsString('Could not read cookies', $result['message']);
    }

    public function test_it_parses_yt_dlp_progress_lines(): void
    {
        $this->assertSame([42.5, '3.10MiB'], $this->service->parseDownloadProgress('[download]  42.5% of ~  3.10MiB at 1.2MiB/s ETA 00:02'));
        $this->assertSame(
            [100.0, '2.81MiB'],
            $this->service->parseDownloadProgress("[download]  10.0% of 2.81MiB at 1MiB/s\n[download] 100.0% of 2.81MiB in 00:00:01")
        );
        $this->assertSame([7.0, null], $this->service->parseDownloadProgress('[download]   7.0% of unknown size'));
        $this->assertNull($this->service->parseDownloadProgress('[youtube] TpHlTBRLHgs: Downloading webpage'));
    }

    public function test_yt_dlp_version_detection(): void
    {
        Process::fake(['*' => "2026.08.19\n"]);

        $this->assertTrue($this->service->checkYtDlpInstalled());
        $this->assertSame('2026.08.19', $this->service->getYtDlpVersion());
    }

    public function test_yt_dlp_reported_missing_when_command_fails(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);

        $this->assertFalse($this->service->checkYtDlpInstalled());
        $this->assertNull($this->service->getYtDlpVersion());
    }
}
