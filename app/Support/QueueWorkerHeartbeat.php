<?php

namespace App\Support;

use App\Models\Download;
use Illuminate\Support\Facades\Cache;

/**
 * Tracks whether a queue worker is running, so the UI can explain why downloads
 * stay "pending" (no `php artisan queue:work` / `composer dev` running).
 */
class QueueWorkerHeartbeat
{
    private const KEY = 'queue-worker-heartbeat';

    /**
     * A worker polls every few seconds while idle; allow some slack.
     */
    private const ALIVE_SECONDS = 30;

    /**
     * Called whenever a worker polls the queue (JobPopping event).
     */
    public static function beat(): void
    {
        $last = (int) Cache::get(self::KEY, 0);

        // Throttle cache writes: workers poll every ~3 seconds
        if (now()->timestamp - $last >= 5) {
            Cache::put(self::KEY, now()->timestamp, now()->addMinutes(10));
        }
    }

    public static function isAlive(): bool
    {
        if (now()->timestamp - (int) Cache::get(self::KEY, 0) <= self::ALIVE_SECONDS) {
            return true;
        }

        // A worker busy with a long download does not poll, but the download's
        // progress updates show it is still alive
        return Download::where('status', 'processing')
            ->where('updated_at', '>=', now()->subSeconds(120))
            ->exists();
    }
}
