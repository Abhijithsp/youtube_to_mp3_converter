<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use App\Models\Download;
use App\Support\QueueWorkerHeartbeat;
use Illuminate\Support\Collection;

class QueueController extends Controller
{
    public function status()
    {
        $pending = DB::table('jobs')->count();
        $failed = DB::table('failed_jobs')->count();
        $processing = Download::where('status', 'processing')->count();

        return response()->json([
            'pending' => $pending,
            'processing' => $processing,
            'failed' => $failed,
            'worker_running' => QueueWorkerHeartbeat::isAlive(),
        ]);
    }

    public function jobs()
    {
        $rows = DB::table('jobs')->orderBy('id', 'asc')->get();
        $downloads = $this->downloadsFor($rows);

        $jobs = $rows->map(function ($job) use ($downloads) {
            $download = $downloads->get($this->downloadIdFromPayload($job->payload));

            return [
                'id' => $job->id,
                'queue' => $job->queue,
                'attempts' => $job->attempts,
                'reserved_at' => $job->reserved_at,
                'created_at' => $job->created_at,
                'download' => $download ? [
                    'id' => $download->id,
                    'title' => $download->title,
                    'thumbnail_url' => $download->thumbnail_url,
                ] : null,
            ];
        });

        return response()->json($jobs);
    }

    public function failedJobs()
    {
        $rows = DB::table('failed_jobs')->orderBy('failed_at', 'desc')->get();
        $downloads = $this->downloadsFor($rows);

        $jobs = $rows->map(function ($job) use ($downloads) {
            $download = $downloads->get($this->downloadIdFromPayload($job->payload));

            return [
                'id' => $job->id,
                // queue:retry looks failed jobs up by UUID
                'uuid' => $job->uuid,
                'connection' => $job->connection,
                'queue' => $job->queue,
                'exception' => $job->exception,
                'failed_at' => $job->failed_at,
                'download' => $download ? [
                    'id' => $download->id,
                    'title' => $download->title,
                ] : null,
            ];
        });

        return response()->json($jobs);
    }
    public function retryAllFailed()
    {
        Artisan::call('queue:retry', ['id' => 'all']);
        return response()->json(['message' => 'All failed jobs retried']);
    }

    public function retryJob($id)
    {
        // The id param for queue:retry works on the failed_jobs UUID or ID.
        Artisan::call('queue:retry', ['id' => $id]);
        return response()->json(['message' => 'Job retried']);
    }

    /**
     * Remove failed jobs. With download_ids, only the jobs of those downloads go
     * (the ones the Queue page showed); without, every failed job is removed.
     */
    public function clearFailed(Request $request)
    {
        $data = $request->validate([
            'download_ids' => 'sometimes|array',
            'download_ids.*' => 'integer',
        ]);

        if (!$request->has('download_ids')) {
            Artisan::call('queue:flush');
            return response()->json(['message' => 'Failed jobs cleared']);
        }

        $downloadIds = array_map('intval', $data['download_ids']);

        $ids = DB::table('failed_jobs')->get(['id', 'payload'])
            ->filter(fn ($job) => in_array($this->downloadIdFromPayload($job->payload), $downloadIds, true))
            ->pluck('id');

        DB::table('failed_jobs')->whereIn('id', $ids)->delete();

        return response()->json(['message' => 'Failed jobs cleared', 'cleared' => $ids->count()]);
    }

    public function cancelJob($id)
    {
        $deleted = DB::table('jobs')->where('id', $id)->delete();
        
        if ($deleted) {
            return response()->json(['message' => 'Job cancelled']);
        }
        
        return response()->json(['message' => 'Job not found'], 404);
    }

    /**
     * Load the downloads referenced by a set of queue rows in one query
     */
    private function downloadsFor(Collection $rows): Collection
    {
        $ids = $rows->map(fn ($job) => $this->downloadIdFromPayload($job->payload))->filter()->unique();

        return Download::whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * Read the Download ID from a queued job payload without unserialize(), which would
     * instantiate arbitrary classes from database contents. SerializesModels stores models
     * as a ModelIdentifier: ...s:5:"class";s:19:"App\Models\Download";s:2:"id";i:42;...
     */
    private function downloadIdFromPayload(?string $payload): ?int
    {
        $command = json_decode((string) $payload, true)['data']['command'] ?? null;

        if (!is_string($command)) {
            return null;
        }

        $class = preg_quote(serialize(Download::class), '/');

        return preg_match('/s:5:"class";' . $class . 's:2:"id";i:(\d+);/', $command, $matches)
            ? (int) $matches[1]
            : null;
    }
}
