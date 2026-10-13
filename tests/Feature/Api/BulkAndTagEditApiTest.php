<?php

namespace Tests\Feature\Api;

use App\Jobs\DownloadYoutubeAudioJob;
use App\Models\Download;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\UsesTemporaryDownloadDirectory;
use Tests\TestCase;

class BulkAndTagEditApiTest extends TestCase
{
    use RefreshDatabase, UsesTemporaryDownloadDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDownloadDirectory();
    }

    public function test_several_links_are_queued_and_spaced_out(): void
    {
        Queue::fake();
        Setting::set('rate_limit_seconds', 5);

        $this->postJson('/api/downloads/bulk', [
            'urls' => ['https://www.youtube.com/watch?v=TpHlTBRLHgs', 'https://youtu.be/MnH03XLqeRs'],
            'format' => 'flac',
            'quality' => '320',
        ])->assertCreated()
            ->assertJsonCount(2, 'queued')
            ->assertJsonCount(0, 'skipped')
            ->assertJsonPath('queued.0.youtube_id', 'TpHlTBRLHgs')
            ->assertJsonPath('queued.1.format', 'flac')
            ->assertJsonPath('message', 'Queued 2 downloads.');

        $this->assertDatabaseHas('downloads', ['youtube_id' => 'MnH03XLqeRs', 'title' => Download::PLACEHOLDER_TITLE, 'status' => 'pending']);

        $delays = Queue::pushed(DownloadYoutubeAudioJob::class)
            ->map(fn ($job) => $job->delay ? (int) round(now()->diffInSeconds($job->delay)) : 0)
            ->values()
            ->all();
        $this->assertSame([0, 5], $delays);
    }

    public function test_bulk_skips_bad_duplicate_playlist_and_existing_links_with_reasons(): void
    {
        Queue::fake();
        Download::factory()->completed($this->makeAudioFile())->create(['youtube_id' => 'MnH03XLqeRs', 'format' => 'mp3', 'quality' => '320']);

        $response = $this->postJson('/api/downloads/bulk', [
            'urls' => [
                'https://www.youtube.com/watch?v=TpHlTBRLHgs',
                'https://youtu.be/TpHlTBRLHgs',
                'https://www.youtube.com/playlist?list=PL123',
                'https://vimeo.com/123',
                'https://youtu.be/MnH03XLqeRs',
            ],
            'format' => 'mp3',
            'quality' => '320',
        ])->assertCreated()->assertJsonCount(1, 'queued');

        $this->assertSame([
            'Listed twice',
            'Playlist link: import it on the Playlist page',
            'Not a YouTube link',
            'Already downloaded',
        ], array_column($response->json('skipped'), 'reason'));

        Queue::assertPushed(DownloadYoutubeAudioJob::class, 1);
    }

    public function test_bulk_reports_when_nothing_could_be_queued(): void
    {
        Queue::fake();

        $this->postJson('/api/downloads/bulk', ['urls' => ['not a link'], 'format' => 'mp3', 'quality' => '320'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Nothing was queued.')
            ->assertJsonPath('skipped.0.reason', 'Not a YouTube link');
    }

    public function test_bulk_validates_input(): void
    {
        $this->postJson('/api/downloads/bulk', ['urls' => [], 'format' => 'ogg', 'quality' => '1'])
            ->assertJsonValidationErrors(['urls', 'format', 'quality']);

        $this->postJson('/api/downloads/bulk', [
            'urls' => array_fill(0, 51, 'https://youtu.be/TpHlTBRLHgs'),
            'format' => 'mp3',
            'quality' => '320',
        ])->assertJsonValidationErrors('urls');
    }

    /**
     * Fake ffmpeg: writes the output file (last argument) like the real tool would.
     */
    private function fakeFfmpeg(): void
    {
        Process::fake(function (PendingProcess $process) {
            file_put_contents(end($process->command), 'retagged');

            return Process::result();
        });
    }

    public function test_editing_tags_retags_and_renames_the_file(): void
    {
        $this->fakeFfmpeg();
        $old = $this->makeAudioFile('Wrong Title (Official Video).mp3', 'original');
        $download = Download::factory()->completed($old)->create(['title' => 'Wrong Title (Official Video)', 'genre' => 'Pop']);

        $this->patchJson("/api/downloads/{$download->id}", [
            'title' => 'D-Sturb - LADY',
            'artist' => 'D-Sturb',
            'album' => 'Hardstyle Hits',
            'year' => 2019,
            'genre' => '',
        ])->assertOk()
            ->assertJsonPath('download.title', 'D-Sturb - LADY')
            ->assertJsonPath('download.artist', 'D-Sturb')
            ->assertJsonPath('download.genre', null)
            ->assertJsonPath('download.year', 2019);

        $new = $this->downloadDir . DIRECTORY_SEPARATOR . 'D-Sturb - LADY.mp3';
        $this->assertSame($new, $download->fresh()->file_path);
        $this->assertFileExists($new);
        $this->assertFileDoesNotExist($old);
        $this->assertSame('retagged', file_get_contents($new));

        Process::assertRan(function (PendingProcess $process) {
            return in_array('title=LADY', $process->command, true)
                && in_array('artist=D-Sturb', $process->command, true)
                && in_array('date=2019', $process->command, true)
                // Cleared fields remove the tag, and cover art is kept
                && in_array('genre=', $process->command, true)
                && in_array('-map', $process->command, true);
        });
    }

    public function test_renaming_never_overwrites_another_file(): void
    {
        $this->fakeFfmpeg();
        $this->makeAudioFile('Song.mp3', 'another download');
        $download = Download::factory()->completed($this->makeAudioFile('Old.mp3'))->create();

        $this->patchJson("/api/downloads/{$download->id}", ['title' => 'Song'])->assertOk();

        $this->assertSame($this->downloadDir . DIRECTORY_SEPARATOR . 'Song (1).mp3', $download->fresh()->file_path);
        $this->assertSame('another download', file_get_contents($this->downloadDir . '/Song.mp3'));

        // Saving again keeps "Song (1).mp3" instead of moving it to "Song (2).mp3"
        $this->patchJson("/api/downloads/{$download->id}", ['title' => 'Song', 'artist' => 'X'])->assertOk();
        $this->assertSame($this->downloadDir . DIRECTORY_SEPARATOR . 'Song (1).mp3', $download->fresh()->file_path);
    }

    public function test_the_file_can_keep_its_name(): void
    {
        $this->fakeFfmpeg();
        $path = $this->makeAudioFile('Keep Me.mp3');
        $download = Download::factory()->completed($path)->create();

        $this->patchJson("/api/downloads/{$download->id}", ['title' => 'Another Title', 'rename' => false])->assertOk();

        $this->assertSame($path, $download->fresh()->file_path);
        $this->assertSame('Another Title', $download->fresh()->title);
    }

    public function test_only_finished_downloads_with_a_file_can_be_edited(): void
    {
        $pending = Download::factory()->create();
        $missing = Download::factory()->completed()->create();

        $this->patchJson("/api/downloads/{$pending->id}", ['title' => 'X'])->assertUnprocessable();
        $this->patchJson("/api/downloads/{$missing->id}", ['title' => 'X'])->assertUnprocessable();

        $done = Download::factory()->completed($this->makeAudioFile())->create();
        $this->patchJson("/api/downloads/{$done->id}", ['title' => '', 'year' => 1500])
            ->assertJsonValidationErrors(['title', 'year']);
    }

    public function test_tagging_errors_leave_the_record_unchanged(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'Invalid data found', exitCode: 1)]);
        $path = $this->makeAudioFile('Song.mp3');
        $download = Download::factory()->completed($path)->create(['title' => 'Song']);

        $this->patchJson("/api/downloads/{$download->id}", ['title' => 'New'])
            ->assertStatus(500)
            ->assertJsonPath('success', false);

        $this->assertSame('Song', $download->fresh()->title);
        $this->assertSame($path, $download->fresh()->file_path);
    }
}
