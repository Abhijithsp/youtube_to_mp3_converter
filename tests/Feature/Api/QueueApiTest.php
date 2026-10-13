<?php

namespace Tests\Feature\Api;

use App\Jobs\DownloadYoutubeAudioJob;
use App\Models\Download;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class QueueApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Store jobs in the jobs table like the real app does
        config(['queue.default' => 'database']);
    }

    private function failJob(int $jobId): string
    {
        $job = DB::table('jobs')->find($jobId);
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => $job->queue,
            'payload' => $job->payload,
            'exception' => 'RuntimeException: boom',
            'failed_at' => now(),
        ]);
        DB::table('jobs')->delete($jobId);

        return $uuid;
    }

    public function test_status_counts_pending_processing_and_failed(): void
    {
        DownloadYoutubeAudioJob::dispatch(Download::factory()->create());
        DownloadYoutubeAudioJob::dispatch(Download::factory()->create());
        Download::factory()->processing()->create();
        $this->failJob(DB::table('jobs')->value('id'));

        $this->getJson('/api/queue/status')->assertExactJson([
            'pending' => 1,
            'processing' => 1,
            'failed' => 1,
            // The processing download was updated just now, so a worker is busy with it
            'worker_running' => true,
        ]);
    }

    public function test_worker_status_follows_the_queue_heartbeat(): void
    {
        $this->getJson('/api/queue/status')->assertJsonPath('worker_running', false);

        // What a polling worker (queue:work or queue:listen) fires every few seconds
        event(new JobPopping('database'));

        $this->getJson('/api/queue/status')->assertJsonPath('worker_running', true);

        $this->travel(2)->minutes();
        $this->getJson('/api/queue/status')->assertJsonPath('worker_running', false);
    }

    public function test_jobs_lists_queued_downloads_with_their_titles(): void
    {
        $download = Download::factory()->create(['title' => 'D-Sturb - LADY']);
        DownloadYoutubeAudioJob::dispatch($download);

        $this->getJson('/api/queue/jobs')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.download.id', $download->id)
            ->assertJsonPath('0.download.title', 'D-Sturb - LADY');
    }

    public function test_jobs_payload_is_read_without_unserializing_objects(): void
    {
        // A payload with a serialized object of an unexpected class must not be instantiated
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['data' => ['command' => 'O:8:"stdClass":1:{s:3:"foo";s:3:"bar";}']]),
            'attempts' => 0,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $this->getJson('/api/queue/jobs')->assertOk()->assertJsonPath('0.download', null);
    }

    public function test_failed_jobs_are_listed_and_can_be_retried_or_cleared(): void
    {
        $download = Download::factory()->failed()->create(['title' => 'Nothing Like The Oldschool']);
        DownloadYoutubeAudioJob::dispatch($download);
        $uuid = $this->failJob(DB::table('jobs')->value('id'));

        $this->getJson('/api/queue/failed')
            ->assertOk()
            ->assertJsonPath('0.download.title', 'Nothing Like The Oldschool')
            ->assertJsonPath('0.exception', 'RuntimeException: boom')
            ->assertJsonPath('0.uuid', $uuid);

        $this->postJson("/api/queue/failed/{$uuid}/retry")->assertOk();
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('jobs', 1);

        $this->failJob(DB::table('jobs')->value('id'));
        $this->postJson('/api/queue/retry-all')->assertOk();
        $this->assertDatabaseCount('jobs', 1);

        $this->failJob(DB::table('jobs')->value('id'));
        $this->postJson('/api/queue/clear-failed')->assertOk();
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_a_queued_job_can_be_cancelled(): void
    {
        DownloadYoutubeAudioJob::dispatch(Download::factory()->create());
        $jobId = DB::table('jobs')->value('id');

        $this->postJson("/api/queue/jobs/{$jobId}/cancel")->assertOk();
        $this->postJson("/api/queue/jobs/{$jobId}/cancel")->assertNotFound();

        $this->assertDatabaseCount('jobs', 0);
    }
}
