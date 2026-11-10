<?php

namespace Tests\Feature\Jobs;

use App\Exceptions\InsufficientDiskSpaceException;
use App\Jobs\DownloadYoutubeAudioJob;
use App\Jobs\DownloadYoutubeVideoJob;
use App\Models\Download;
use App\Services\DiskSpaceService;
use App\Services\YouTubeService;
use App\Support\SongMover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\UsesTemporaryDownloadDirectory;
use Tests\TestCase;

class DownloadYoutubeVideoJobTest extends TestCase
{
    use RefreshDatabase, UsesTemporaryDownloadDirectory;

    private const URL = 'https://www.youtube.com/watch?v=TpHlTBRLHgs';

    private const FORMATS = [
        ['vcodec' => 'none', 'ext' => 'm4a', 'filesize' => 3_000_000],
        ['vcodec' => 'none', 'ext' => 'webm', 'filesize' => 4_000_000],
        ['vcodec' => 'avc1.640028', 'height' => 1080, 'width' => 1920, 'fps' => 30, 'tbr' => 4000, 'filesize' => 50_000_000],
        ['vcodec' => 'vp09.00.40.08', 'height' => 1080, 'width' => 1920, 'fps' => 30, 'tbr' => 3000, 'filesize' => 40_000_000],
        ['vcodec' => 'av01.0.12M.08', 'height' => 2160, 'width' => 3840, 'fps' => 60, 'tbr' => 15000, 'filesize_approx' => 200_000_000],
        ['vcodec' => 'vp09.00.51.08', 'height' => 2160, 'width' => 3840, 'fps' => 60, 'tbr' => 18000, 'filesize' => 250_000_000],
        ['vcodec' => 'avc1.42001E', 'height' => 1920, 'width' => 1080, 'fps' => 30, 'tbr' => 3000],
        // A combined stream: never used, it would hide the better video-only ones
        ['vcodec' => 'avc1.42001E', 'acodec' => 'mp4a.40.2', 'height' => 360, 'width' => 640, 'fps' => 30, 'tbr' => 600, 'filesize' => 9_000_000],
    ];

    /** @var array<int, array> Commands the fake tools received */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDownloadDirectory();
    }

    private function fakeYtDlp(?\Closure $download = null): void
    {
        Process::fake(function (PendingProcess $process) use ($download) {
            $command = $process->command;
            $this->commands[] = $command;

            if (in_array('--dump-json', $command, true)) {
                return json_encode(['id' => 'TpHlTBRLHgs', 'title' => 'LADY', 'duration' => 177, 'thumbnail' => 'https://i.ytimg.com/t.jpg', 'formats' => self::FORMATS]);
            }

            if ($download) {
                return $download($command);
            }

            $template = $command[array_search('-o', $command, true) + 1];
            file_put_contents(str_replace('%(ext)s', 'mp4', $template), 'joined-video');

            return Process::result();
        });
    }

    private function video(array $attributes = []): Download
    {
        return Download::factory()->create($attributes + [
            'youtube_url' => self::URL,
            'youtube_id' => 'TpHlTBRLHgs',
            'title' => 'D-Sturb - LADY',
            'format' => 'mp4',
            'video_height' => 1080,
        ]);
    }

    private function runJob(Download $download): void
    {
        (new DownloadYoutubeVideoJob($download))->handle(app(YouTubeService::class), app(DiskSpaceService::class));
    }

    public function test_a_video_is_saved_in_the_videos_folder_without_re_encoding(): void
    {
        $this->fakeYtDlp();
        $download = $this->video();

        $this->runJob($download);

        $download->refresh();
        $path = $this->downloadDir . '/Videos/D-Sturb - LADY.mp4';
        $this->assertSame('completed', $download->status);
        $this->assertSame(100, $download->progress);
        $this->assertSame($path, $download->file_path);
        $this->assertSame('joined-video', file_get_contents($path));
        $this->assertSame(12, $download->file_size);

        $command = end($this->commands);
        $this->assertContains('--merge-output-format', $command);
        $this->assertStringContainsString('bv[height=1080][vcodec^=avc1]+ba[ext=m4a]', $command[array_search('-f', $command, true) + 1]);

        // yt-dlp only: ffmpeg is never asked to convert anything
        $this->assertEmpty(array_filter($this->commands, fn ($c) => $c[0] !== 'yt-dlp'));
    }

    public function test_progress_covers_both_streams(): void
    {
        $service = app(YouTubeService::class);
        $streams = 0;

        $this->assertNull($service->parseVideoProgress('[download] Destination: a.f137.mp4', $streams));
        $this->assertSame([45.0, '10.00MiB'], $service->parseVideoProgress('[download]  50.0% of 10.00MiB', $streams));
        $this->assertSame([90.0, '10.00MiB'], $service->parseVideoProgress('[download] 100.0% of 10.00MiB', $streams));
        $this->assertNull($service->parseVideoProgress('[download] Destination: a.f140.m4a', $streams));
        $this->assertSame([95.0, '1.00MiB'], $service->parseVideoProgress('[download]  50.0% of 1.00MiB', $streams));
        $this->assertSame([100.0, '1.00MiB'], $service->parseVideoProgress('[download] 100.0% of 1.00MiB', $streams));
    }

    public function test_a_failed_download_leaves_no_files_and_keeps_the_download_retryable(): void
    {
        $this->fakeYtDlp(fn () => Process::result(errorOutput: 'ERROR: HTTP Error 403', exitCode: 1));
        $download = $this->video();

        try {
            $this->runJob($download);
            $this->fail('The job should rethrow so the queue retries it');
        } catch (\Exception $e) {
            $this->assertStringContainsString('HTTP Error 403', $e->getMessage());
        }

        $this->assertSame('pending', $download->fresh()->status);
        $this->assertSame([], glob($this->downloadDir . '/Videos/*'));
    }

    public function test_cancelling_removes_the_partial_files(): void
    {
        $download = $this->video();

        $this->fakeYtDlp(function () use ($download) {
            $download->update(['status' => 'cancelled']);

            return Process::result();
        });

        $this->runJob($download);

        $this->assertSame('Cancelled', $download->fresh()->status_text);
        $this->assertSame([], glob($this->downloadDir . '/Videos/*'));
    }

    public function test_disk_space_counts_the_streams_and_the_joined_file(): void
    {
        $this->fakeYtDlp();
        // 53 MB video and audio, doubled while they are joined
        $this->mock(DiskSpaceService::class, fn ($mock) => $mock->shouldReceive('hasEnoughSpace')->with(102)->once()->andReturnFalse());

        $this->expectException(InsufficientDiskSpaceException::class);

        $this->runJob($this->video());
    }

    public function test_the_job_runs_longer_than_audio_but_stays_below_the_queue_retry_time(): void
    {
        $job = new DownloadYoutubeVideoJob($this->video());

        $this->assertSame(7200, $job->timeout);
        // A running job must never be handed to a second worker
        $this->assertGreaterThan($job->timeout, config('queue.connections.database.retry_after'));
    }

    public function test_video_qualities_list_what_the_video_really_has(): void
    {
        $this->fakeYtDlp();

        $qualities = app(YouTubeService::class)->extractVideoInfo(self::URL)['video_qualities'];

        $this->assertSame([2160, 1920, 1080], array_column($qualities, 'height'));

        $this->assertSame(['height' => 2160, 'label' => '2160p60 (4K)', 'codec' => 'AV1', 'compatible' => false, 'size' => 203_000_000], $qualities[0]);
        // A portrait video is named after its short side, and its size may be unknown
        $this->assertSame(['height' => 1920, 'label' => '1080p', 'codec' => 'H.264', 'compatible' => true, 'size' => null], $qualities[1]);
        // H.264 is preferred over the smaller VP9 stream
        $this->assertSame(['height' => 1080, 'label' => '1080p', 'codec' => 'H.264', 'compatible' => true, 'size' => 53_000_000], $qualities[2]);
    }

    public function test_store_queues_a_video_job_at_the_chosen_resolution(): void
    {
        Queue::fake();

        $this->postJson('/api/downloads', ['youtube_url' => self::URL, 'format' => 'mp4', 'video_height' => 2160, 'title' => 'LADY'])
            ->assertCreated()
            ->assertJsonPath('download.video_height', 2160)
            ->assertJsonPath('download.is_video', true);

        Queue::assertPushed(DownloadYoutubeVideoJob::class);
        Queue::assertNotPushed(DownloadYoutubeAudioJob::class);
    }

    public function test_store_rejects_a_video_without_resolution_or_with_audio_edits(): void
    {
        Queue::fake();

        $this->postJson('/api/downloads', ['youtube_url' => self::URL, 'format' => 'mp4'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('video_height');

        $this->postJson('/api/downloads', ['youtube_url' => self::URL, 'format' => 'mp4', 'video_height' => 1080, 'trim_start' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('format');

        // Bulk stays audio only
        $this->postJson('/api/downloads/bulk', ['urls' => [self::URL], 'format' => 'mp4', 'quality' => 320])
            ->assertUnprocessable();

        Queue::assertNothingPushed();
    }

    public function test_the_same_video_is_only_reused_at_the_same_resolution(): void
    {
        Queue::fake();
        $path = $this->makeAudioFile('video.mp4');
        $existing = Download::factory()->completed($path)->create(['youtube_id' => 'TpHlTBRLHgs', 'format' => 'mp4', 'quality' => '320', 'video_height' => 1080]);

        $this->postJson('/api/downloads', ['youtube_url' => self::URL, 'format' => 'mp4', 'video_height' => 1080])
            ->assertOk()
            ->assertJsonPath('download.id', $existing->id);

        $this->postJson('/api/downloads', ['youtube_url' => self::URL, 'format' => 'mp4', 'video_height' => 720])
            ->assertCreated();
    }

    public function test_retry_runs_the_video_job(): void
    {
        Queue::fake();
        $download = $this->video(['status' => 'failed']);

        $this->postJson("/api/downloads/{$download->id}/retry")->assertOk();

        Queue::assertPushed(DownloadYoutubeVideoJob::class);
    }

    public function test_a_finished_video_is_served_as_mp4_and_kept_out_of_zips(): void
    {
        $download = Download::factory()->completed($this->makeAudioFile('video.mp4'))->create(['format' => 'mp4', 'video_height' => 1080]);

        $this->get("/api/downloads/{$download->id}/stream")
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4');

        $this->getJson("/api/downloads/zip?ids={$download->id}")->assertNotFound();

        // The video player seeks with range requests
        $this->get("/api/downloads/{$download->id}/stream", ['Range' => 'bytes=0-3'])
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-3/16');
    }

    public function test_moving_to_a_new_folder_keeps_videos_in_a_videos_subfolder(): void
    {
        mkdir($this->downloadDir . '/Videos');
        $path = $this->downloadDir . '/Videos/LADY.mp4';
        file_put_contents($path, 'video');
        $download = Download::factory()->completed($path)->create(['format' => 'mp4', 'video_height' => 1080]);

        $target = $this->downloadDir . '/new';
        mkdir($target);

        $this->assertSame(['moved' => 1, 'failed' => []], app(SongMover::class)->move($this->downloadDir, $target));

        $this->assertFileExists($target . '/Videos/LADY.mp4');
        $this->assertFileDoesNotExist($path);
        $this->assertSame($target . DIRECTORY_SEPARATOR . 'Videos' . DIRECTORY_SEPARATOR . 'LADY.mp4', $download->fresh()->file_path);
    }
}
