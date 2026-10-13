<?php

namespace App\Services;

use App\Models\Setting;
use Exception;
use Illuminate\Support\Facades\Log;

class DiskSpaceService
{
    /**
     * Get available disk space in megabytes
     */
    public function checkAvailableSpace(?string $path = null): int
    {
        try {
            $path = $path ?? Setting::downloadPath();

            // Ensure directory exists
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }

            $freeSpace = disk_free_space($path);

            if ($freeSpace === false) {
                throw new Exception('Failed to check disk space');
            }

            // Convert bytes to megabytes
            return (int) ($freeSpace / 1024 / 1024);
        } catch (Exception $e) {
            Log::error('Failed to check disk space', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Check if there's enough disk space for download
     */
    public function hasEnoughSpace(int $requiredMB = 100): bool
    {
        $availableSpace = $this->checkAvailableSpace();
        $minRequired = (int) Setting::get('min_disk_space_mb', config('youtube-converter.min_disk_space_mb'));

        return $availableSpace >= ($minRequired + $requiredMB);
    }

    /**
     * Get total disk space in megabytes
     */
    public function getTotalSpace(?string $path = null): int
    {
        try {
            $path = $path ?? Setting::downloadPath();

            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }

            $totalSpace = disk_total_space($path);

            if ($totalSpace === false) {
                throw new Exception('Failed to get total disk space');
            }

            return (int) ($totalSpace / 1024 / 1024);
        } catch (Exception $e) {
            Log::error('Failed to get total disk space', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Get used disk space in megabytes
     */
    public function getUsedSpace(?string $path = null): int
    {
        $total = $this->getTotalSpace($path);
        $available = $this->checkAvailableSpace($path);

        return max(0, $total - $available);
    }

    /**
     * Get disk usage percentage
     */
    public function getUsagePercentage(?string $path = null): float
    {
        $total = $this->getTotalSpace($path);

        if ($total === 0) {
            return 0;
        }

        $used = $this->getUsedSpace($path);

        return round(($used / $total) * 100, 2);
    }

    /**
     * Format bytes to human-readable format
     */
    public function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Estimate file size based on duration and quality
     */
    public function estimateFileSize(int $durationSeconds, int $quality = 320): int
    {
        // Rough estimation: bitrate * duration / 8 (convert bits to bytes)
        // Add 10% overhead for container format
        $estimatedBytes = ($quality * 1000 * $durationSeconds / 8) * 1.1;

        // Convert to MB
        return (int) ceil($estimatedBytes / 1024 / 1024);
    }
}
