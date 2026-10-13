<?php

namespace Tests\Unit\Services;

use App\Services\AudioConverterService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class AudioConverterServiceTest extends TestCase
{
    private AudioConverterService $service;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AudioConverterService();
        $this->dir = storage_path('framework/testing/converter dir with spaces');
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /**
     * Fake ffmpeg: "writes" the output file (the last argument) and succeeds.
     */
    private function fakeFfmpeg(): void
    {
        Process::fake(function (PendingProcess $process) {
            file_put_contents(end($process->command), 'converted');

            return Process::result();
        });
    }

    public function test_paths_with_spaces_are_passed_as_single_arguments(): void
    {
        $this->fakeFfmpeg();
        $input = $this->dir . '/my input file.webm';
        $output = $this->dir . '/My Song (Official Video).mp3';
        file_put_contents($input, 'raw');

        $this->assertTrue($this->service->convert($input, $output, 'mp3', 256));

        Process::assertRan(fn (PendingProcess $process) => is_array($process->command)
            && in_array($input, $process->command, true)
            && end($process->command) === $output
            && in_array('256k', $process->command, true)
            && in_array('libmp3lame', $process->command, true));
        $this->assertFileExists($output);
    }

    public function test_flac_conversion_uses_the_flac_codec_without_a_bitrate(): void
    {
        $this->fakeFfmpeg();
        $input = $this->dir . '/in.webm';
        file_put_contents($input, 'raw');

        $this->service->convert($input, $this->dir . '/out.flac', 'flac', 320);

        Process::assertRan(fn (PendingProcess $process) => in_array('flac', $process->command, true)
            && !in_array('-b:a', $process->command, true));
    }

    public function test_conversion_fails_for_missing_input(): void
    {
        Process::fake();

        $this->expectExceptionMessage('Input file does not exist');

        $this->service->convert($this->dir . '/missing.webm', $this->dir . '/out.mp3');
    }

    public function test_conversion_fails_when_ffmpeg_fails(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'Invalid data found', exitCode: 1)]);
        $input = $this->dir . '/in.webm';
        file_put_contents($input, 'raw');

        $this->expectExceptionMessage('Conversion failed: Invalid data found');

        $this->service->convert($input, $this->dir . '/out.mp3');
    }

    public function test_it_parses_old_and_new_ffmpeg_progress_formats(): void
    {
        // ffmpeg < 6
        $this->assertSame([1280, '00:00:10.00'], $this->service->parseProgress('size=    1280kB time=00:00:10.00 bitrate= 1048.6kbits/s'));
        // ffmpeg 6+ prints KiB (the old regex never matched this, so progress stayed at 0%)
        $this->assertSame([72, '00:00:03.00'], $this->service->parseProgress('size=      72KiB time=00:00:03.00 bitrate= 196.6kbits/s'));
        // several updates in one chunk: the latest wins
        $this->assertSame(
            [2048, '00:01:20.50'],
            $this->service->parseProgress("size=1024KiB time=00:00:40.00 x\rsize=2048KiB time=00:01:20.50 x")
        );
        $this->assertNull($this->service->parseProgress('size=N/A time=N/A bitrate=N/A'));
    }

    public function test_ffmpeg_version_detection(): void
    {
        Process::fake(['*' => "ffmpeg version 9.0.1-full_build-www.gyan.dev Copyright (c) 2000-2026\n"]);

        $this->assertTrue($this->service->validateFfmpegInstalled());
        $this->assertSame('9.0.1-full_build-www.gyan.dev', $this->service->getFfmpegVersion());

        Process::assertRan(fn (PendingProcess $process) => $process->command === ['ffmpeg', '-version']);
    }

    public function test_ffmpeg_reported_missing_when_command_fails(): void
    {
        Process::fake(['*' => Process::result(errorOutput: "'ffmpeg' is not recognized", exitCode: 1)]);

        $this->assertFalse($this->service->validateFfmpegInstalled());
        $this->assertNull($this->service->getFfmpegVersion());
    }
}
