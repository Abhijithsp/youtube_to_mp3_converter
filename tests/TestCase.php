<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Process;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Never run the real yt-dlp/ffmpeg from a test by accident: every command must be
        // faked with Process::fake(). The integration tests opt out explicitly.
        Process::fake([]);
        Process::preventStrayProcesses();
    }
}
