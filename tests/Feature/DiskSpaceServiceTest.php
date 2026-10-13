<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\DiskSpaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesTemporaryDownloadDirectory;
use Tests\TestCase;

class DiskSpaceServiceTest extends TestCase
{
    use RefreshDatabase, UsesTemporaryDownloadDirectory;

    private DiskSpaceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDownloadDirectory();
        $this->service = new DiskSpaceService();
    }

    public function test_it_reports_free_and_total_space_of_the_configured_directory(): void
    {
        $free = $this->service->checkAvailableSpace();
        $total = $this->service->getTotalSpace();

        $this->assertGreaterThan(0, $free);
        $this->assertGreaterThanOrEqual($free, $total);
        $this->assertGreaterThanOrEqual(0, $this->service->getUsagePercentage());
    }

    public function test_it_creates_the_settings_download_path_when_missing(): void
    {
        $path = $this->downloadDir . '/nested/folder';
        Setting::set('download_path', $path);

        $this->service->checkAvailableSpace();

        $this->assertDirectoryExists($path);
    }

    public function test_required_space_is_the_reserve_plus_the_file_size(): void
    {
        $free = $this->service->checkAvailableSpace();

        Setting::set('min_disk_space_mb', 0);
        $this->assertTrue($this->service->hasEnoughSpace(10));

        // Margins keep the test stable while other programs write to the disk
        Setting::set('min_disk_space_mb', $free - 100);
        $this->assertTrue($this->service->hasEnoughSpace(0));
        $this->assertFalse($this->service->hasEnoughSpace(500));
    }

    public function test_file_size_estimate_and_formatting(): void
    {
        // 180s at 320kbps = 7.2MB + 10% overhead
        $this->assertSame(8, $this->service->estimateFileSize(180, 320));
        $this->assertSame('1.5 KB', $this->service->formatBytes(1536));
        $this->assertSame('1 GB', $this->service->formatBytes(1024 ** 3 + 1));
    }
}
