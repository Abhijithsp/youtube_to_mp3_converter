<?php

namespace Tests\Unit;

use App\Support\ProcessOutput;
use Illuminate\Process\FakeProcessResult;
use PHPUnit\Framework\TestCase;

class ProcessOutputTest extends TestCase
{
    public function test_invalid_utf8_is_scrubbed_so_it_can_be_json_encoded_and_stored(): void
    {
        // "é" as a lone Windows-1252 byte, as yt-dlp prints it on a legacy console
        $result = new FakeProcessResult(exitCode: 1, errorOutput: "ERROR: Caf\xE9 video unavailable");

        $message = ProcessOutput::error($result);

        $this->assertTrue(mb_check_encoding($message, 'UTF-8'));
        $this->assertNotFalse(json_encode($message));
        $this->assertStringContainsString('video unavailable', $message);
    }

    public function test_only_the_last_lines_are_kept(): void
    {
        $banner = implode("\n", array_map(fn ($i) => "ffmpeg banner line {$i}", range(1, 40)));
        $result = new FakeProcessResult(exitCode: 1, errorOutput: "{$banner}\n\nin.webm: Invalid data found when processing input\n");

        $message = ProcessOutput::error($result, 2);

        $this->assertSame("ffmpeg banner line 40\nin.webm: Invalid data found when processing input", $message);
    }

    public function test_falls_back_to_stdout_and_then_the_exit_code(): void
    {
        $this->assertSame('only stdout', ProcessOutput::error(new FakeProcessResult(output: 'only stdout', exitCode: 2)));
        $this->assertSame('exit code 3', ProcessOutput::error(new FakeProcessResult(exitCode: 3)));
    }
}
