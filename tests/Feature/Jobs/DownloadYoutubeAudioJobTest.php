<?php

namespace Tests\Feature\Jobs;

use App\Exceptions\InsufficientDiskSpaceException;
use App\Exceptions\YouTubeDownloadException;
use App\Jobs\DownloadYoutubeAudioJob;
use App\Models\Download;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Setting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\Concerns\UsesTemporaryDownloadDirectory;
use Tests\TestCase;

class DownloadYoutubeAudioJobTest extends TestCase
{
    use RefreshDatabase, UsesTemporaryDownloadDirectory;

    private const VIDEO_INFO = [
        'id' => 'TpHlTBRLHgs',
        'title' => 'D-Sturb - LADY (Official Music Video)',
        'uploader' => 'Q-dance Music',
        'thumbnail' => 'https://i.ytimg.com/vi/TpHlTBRLHgs/maxresdefault.jpg',
        'duration' => 177,
    ];

    /** @var array<int, array> Commands the fake tools received */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDownloadDirectory();
        File::cleanDirectory(storage_path('app/temp'));
        Http::fake(['i.ytimg.com/*' => Http::response('thumbnail-bytes')]);
    }

    /**
     * Fake yt-dlp and ffmpeg: they "write" the files the real tools would write.
     *
     * @param  array<string, \Closure>  $overrides  per-tool behaviour ('info', 'download', 'ffmpeg')
     */
    private function fakeTools(array $videoInfo = self::VIDEO_INFO, array $overrides = []): void
    {
        Process::fake(function (PendingProcess $process) use ($videoInfo, $overrides) {
            $command = $process->command;
            $this->commands[] = $command;

            if ($command[0] === 'yt-dlp' && in_array('--dump-json', $command, true)) {
                return isset($overrides['info']) ? $overrides['info']($command) : json_encode($videoInfo);
            }

            if ($command[0] === 'yt-dlp') {
                if (isset($overrides['download'])) {
                    return $overrides['download']($command);
                }

                // yt-dlp appends the container extension to the -o template
                $output = $command[array_search('-o', $command, true) + 1];
                file_put_contents($output . '.webm', 'webm-audio');

                return Process::result();
            }

            if ($command[0] === 'ffmpeg') {
                if (isset($overrides['ffmpeg'])) {
                    return $overrides['ffmpeg']($command);
                }

                file_put_contents(end($command), 'ffmpeg-output-' . count($this->commands));

                return Process::result();
            }

            throw new RuntimeException('Unexpected command: ' . implode(' ', $command));
        });
    }

    private function runJob(Download $download, int $attempt = 1, int $tries = 3): void
    {
        $job = new DownloadYoutubeAudioJob($download);
        $job->tries = $tries;

        $queueJob = $this->createMock(Job::class);
        $queueJob->method('attempts')->willReturn($attempt);
        $job->setJob($queueJob);

        app()->call([$job, 'handle']);
    }

    private function ffmpegCalls(): array
    {
        return array_values(array_filter($this->commands, fn ($command) => $command[0] === 'ffmpeg'));
    }

    public function test_a_download_runs_through_every_step(): void
    {
        $this->fakeTools();
        $download = Download::factory()->create([
            'youtube_url' => 'https://www.youtube.com/watch?v=TpHlTBRLHgs',
            'youtube_id' => 'TpHlTBRLHgs',
            'title' => Download::PLACEHOLDER_TITLE,
            'artist' => null,
            'format' => 'mp3',
            'quality' => '320',
        ]);

        $this->runJob($download);

        $download->refresh();
        $expectedFile = $this->downloadDir . '/D-Sturb - LADY (Official Music Video).mp3';

        $this->assertSame('completed', $download->status);
        $this->assertSame('Completed', $download->status_text);
        $this->assertSame(100, $download->progress);
        $this->assertNull($download->error_message);
        $this->assertNotNull($download->completed_at);

        // Bug: the title stayed "Fetching..." and the file was saved as fetching.mp3
        $this->assertSame('D-Sturb - LADY (Official Music Video)', $download->title);
        $this->assertSame('Q-dance Music', $download->artist);
        $this->assertSame(177, $download->duration);
        $this->assertSame($expectedFile, $download->file_path);
        $this->assertFileExists($expectedFile);
        $this->assertSame(filesize($expectedFile), $download->file_size);
        $this->assertSame(strlen('webm-audio'), $download->original_file_size);

        // Bug: download_url was always empty
        $this->assertSame(route('downloads.stream', $download), $download->download_url);

        // convert -> tag -> cover art
        [$convert, $tag, $cover] = $this->ffmpegCalls();
        $this->assertContains('libmp3lame', $convert);
        $this->assertContains('320k', $convert);
        $this->assertContains('title=D-Sturb - LADY (Official Music Video)', $tag);
        $this->assertContains('artist=Q-dance Music', $tag);
        $this->assertContains('attached_pic', $cover);

        // Temp files are cleaned up
        $this->assertSame([], glob(storage_path('app/temp/download_*')));
    }

    public function test_a_title_sent_by_the_ui_is_kept_while_missing_details_are_fetched(): void
    {
        $this->fakeTools();
        $download = Download::factory()->create([
            'title' => 'My Own Title',
            'artist' => 'My Artist',
            'duration' => null,
            'thumbnail_url' => 'https://i.ytimg.com/vi/x/hq.jpg',
        ]);

        $this->runJob($download);

        $download->refresh();
        $this->assertSame('My Own Title', $download->title);
        $this->assertSame('My Artist', $download->artist);
        $this->assertSame(177, $download->duration);
        $this->assertSame('https://i.ytimg.com/vi/x/hq.jpg', $download->thumbnail_url);
    }

    public function test_video_info_is_not_fetched_again_when_already_complete(): void
    {
        $this->fakeTools();
        $download = Download::factory()->create([
            'duration' => 100,
            'thumbnail_url' => 'https://i.ytimg.com/vi/x/hq.jpg',
        ]);

        $this->runJob($download);

        $this->assertFalse(collect($this->commands)->contains(fn ($command) => in_array('--dump-json', $command, true)));
        $this->assertSame('completed', $download->fresh()->status);
    }

    public function test_flac_downloads_use_the_flac_codec(): void
    {
        $this->fakeTools();
        $download = Download::factory()->create(['format' => 'flac', 'quality' => '256']);

        $this->runJob($download);

        $this->assertStringEndsWith('.flac', $download->fresh()->file_path);
        $this->assertContains('flac', $this->ffmpegCalls()[0]);
        $this->assertNotContains('-b:a', $this->ffmpegCalls()[0]);
    }

    public function test_files_keep_non_latin_titles(): void
    {
        $this->fakeTools(['title' => 'ആവേശം - ഇലുമിനാറ്റി'] + self::VIDEO_INFO);
        $download = Download::factory()->create(['title' => Download::PLACEHOLDER_TITLE, 'youtube_id' => 'TpHlTBRLHgs']);

        $this->runJob($download);

        // Str::slug() used to return '' for Malayalam, producing a file named ".mp3"
        $this->assertSame($this->downloadDir . '/ആവേശം - ഇലുമിനാറ്റി.mp3', $download->fresh()->file_path);
    }

    public function test_existing_files_are_not_overwritten(): void
    {
        $this->fakeTools();
        $this->makeAudioFile('D-Sturb - LADY (Official Music Video).mp3', 'older download');
        $download = Download::factory()->create(['title' => Download::PLACEHOLDER_TITLE]);

        $this->runJob($download);

        $this->assertSame($this->downloadDir . '/D-Sturb - LADY (Official Music Video) (1).mp3', $download->fresh()->file_path);
        $this->assertSame('older download', file_get_contents($this->downloadDir . '/D-Sturb - LADY (Official Music Video).mp3'));
    }

    public function test_the_download_folder_from_settings_is_used(): void
    {
        $this->fakeTools();
        $customDir = $this->downloadDir . '/custom folder';
        Setting::set('download_path', $customDir);
        $download = Download::factory()->create();

        $this->runJob($download);

        $this->assertStringStartsWith($customDir . '/', $download->fresh()->file_path);
        $this->assertFileExists($download->fresh()->file_path);
    }

    public function test_metadata_failures_do_not_fail_the_download(): void
    {
        $this->fakeTools(overrides: [
            'ffmpeg' => function (array $command) {
                if (in_array('-metadata', $command, true) || in_array('attached_pic', $command, true)) {
                    return Process::result(errorOutput: 'tagging broke', exitCode: 1);
                }
                file_put_contents(end($command), 'converted');

                return Process::result();
            },
        ]);
        $download = Download::factory()->create();

        $this->runJob($download);

        $this->assertSame('completed', $download->fresh()->status);
        $this->assertSame('converted', file_get_contents($download->fresh()->file_path));
    }

    public function test_a_cancelled_download_is_not_processed(): void
    {
        $this->fakeTools();
        $download = Download::factory()->cancelled()->create();

        $this->runJob($download);

        Process::assertNothingRan();
        $this->assertSame('cancelled', $download->fresh()->status);
    }

    public function test_cancelling_during_the_download_stops_the_job_and_cleans_up(): void
    {
        $this->fakeTools(overrides: [
            'download' => function (array $command) {
                $output = $command[array_search('-o', $command, true) + 1];
                file_put_contents($output . '.webm', 'partial');
                // The user presses "Cancel" while yt-dlp is running
                Download::query()->update(['status' => 'cancelled']);

                return Process::result();
            },
        ]);
        $download = Download::factory()->create();

        $this->runJob($download);

        $download->refresh();
        $this->assertSame('cancelled', $download->status);
        $this->assertSame('Cancelled', $download->status_text);
        $this->assertNull($download->file_path);
        $this->assertSame([], $this->ffmpegCalls());
        $this->assertSame([], glob(storage_path('app/temp/download_*')));
        $this->assertSame([], glob($this->downloadDir . '/*'));
    }

    public function test_a_failed_attempt_is_retried_and_cleans_up(): void
    {
        $this->fakeTools(overrides: [
            'download' => function (array $command) {
                file_put_contents($command[array_search('-o', $command, true) + 1] . '.part', 'partial');

                return Process::result(errorOutput: 'HTTP Error 403: Forbidden', exitCode: 1);
            },
        ]);
        $download = Download::factory()->create();

        try {
            $this->runJob($download, attempt: 1, tries: 3);
            $this->fail('The job should re-throw so the queue retries it');
        } catch (YouTubeDownloadException $e) {
            $this->assertStringContainsString('HTTP Error 403', $e->getMessage());
        }

        $download->refresh();
        // Not "failed" yet: the queue will retry, so the UI must not offer a manual retry
        $this->assertSame('pending', $download->status);
        $this->assertSame('Attempt 1 of 3 failed, retrying...', $download->status_text);
        $this->assertStringContainsString('HTTP Error 403', $download->error_message);
        $this->assertSame([], glob(storage_path('app/temp/download_*')));
    }

    public function test_the_last_failed_attempt_marks_the_download_failed(): void
    {
        $download = Download::factory()->processing()->create();
        $job = new DownloadYoutubeAudioJob($download);

        $job->failed(new RuntimeException('HTTP Error 403'));

        $download->refresh();
        $this->assertSame('failed', $download->status);
        $this->assertSame('Failed', $download->status_text);
        $this->assertStringContainsString('HTTP Error 403', $download->error_message);
    }

    public function test_failed_accepts_errors_and_timeouts_not_only_exceptions(): void
    {
        $download = Download::factory()->processing()->create();

        // Used to be failed(Exception $e): a PHP Error caused a TypeError inside failed()
        (new DownloadYoutubeAudioJob($download))->failed(new \TypeError('bad type'));
        $this->assertSame('failed', $download->fresh()->status);

        (new DownloadYoutubeAudioJob($download))->failed(null);
        $this->assertSame('failed', $download->fresh()->status);
    }

    public function test_failed_does_not_overwrite_a_cancellation(): void
    {
        $download = Download::factory()->cancelled()->create();

        (new DownloadYoutubeAudioJob($download))->failed(new RuntimeException('killed'));

        $this->assertSame('cancelled', $download->fresh()->status);
    }

    public function test_insufficient_disk_space_fails_the_attempt(): void
    {
        $this->fakeTools();
        Setting::set('min_disk_space_mb', PHP_INT_MAX >> 30);
        $download = Download::factory()->create();

        $this->expectException(InsufficientDiskSpaceException::class);

        try {
            $this->runJob($download, attempt: 3, tries: 3);
        } finally {
            $this->assertSame([], $this->ffmpegCalls());
        }
    }

    public function test_playlist_progress_is_updated_when_items_finish(): void
    {
        $this->fakeTools();
        $playlist = Playlist::factory()->create(['total_videos' => 2, 'status' => 'processing']);
        $first = Download::factory()->create();
        $second = Download::factory()->create();
        PlaylistItem::factory()->for($playlist)->create(['download_id' => $first->id]);
        PlaylistItem::factory()->for($playlist)->create(['download_id' => $second->id, 'position' => 2]);

        $this->runJob($first);
        $this->assertSame(1, $playlist->fresh()->processed_videos);
        $this->assertSame('processing', $playlist->fresh()->status);

        // A failed item still counts as processed
        (new DownloadYoutubeAudioJob($second))->failed(new RuntimeException('gone'));
        $this->assertSame(2, $playlist->fresh()->processed_videos);
        $this->assertSame('completed', $playlist->fresh()->status);
    }

    public function test_retry_settings_come_from_config(): void
    {
        config(['youtube-converter.job_tries' => 5, 'youtube-converter.job_timeout' => 1200]);

        $job = new DownloadYoutubeAudioJob(Download::factory()->create());

        $this->assertSame(5, $job->tries);
        $this->assertSame(1200, $job->timeout);
    }

    public function test_jobs_for_deleted_downloads_are_discarded(): void
    {
        $this->assertTrue((new DownloadYoutubeAudioJob(Download::factory()->create()))->deleteWhenMissingModels);
    }
}
