<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Process;

abstract class TestCase extends BaseTestCase
{
    /**
     * A cached config (php artisan config:cache / optimize) makes Laravel ignore phpunit.xml,
     * so RefreshDatabase would wipe the real database. Refuse to run instead.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $config = $app['config'];
        $database = $config->get('database.connections.' . $config->get('database.default') . '.database');

        if ($app->configurationIsCached() || $database !== ':memory:') {
            throw new \RuntimeException(
                'Tests must use the in-memory test database, but the config points at "' . $database . '". '
                . 'Run "php artisan config:clear" (a cached config ignores phpunit.xml), then run the tests again.'
            );
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Never run the real yt-dlp/ffmpeg from a test by accident: every command must be
        // faked with Process::fake(). The integration tests opt out explicitly.
        Process::fake([]);
        Process::preventStrayProcesses();
    }
}
