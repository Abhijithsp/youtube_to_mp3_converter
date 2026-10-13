<?php

namespace App\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Str;

class ProcessOutput
{
    /**
     * Short, valid UTF-8 summary of a failed command's error output.
     *
     * On Windows, tools may print in the console's legacy code page; invalid bytes
     * would break JSON responses and utf8mb4 columns. ffmpeg also prefixes every
     * error with a long banner, so only the last lines are kept.
     */
    public static function error(ProcessResult $result, int $lines = 5): string
    {
        $output = mb_scrub(trim($result->errorOutput() ?: $result->output()), 'UTF-8');

        $tail = collect(preg_split('/\R/', $output))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->take(-$lines)
            ->implode("\n");

        return Str::limit($tail, 1000) ?: "exit code {$result->exitCode()}";
    }
}
