<?php

namespace Tests\Feature\Api;

use App\Jobs\ConvertLocalFileJob;
use App\Jobs\DownloadYoutubeAudioJob;
use App\Models\Download;
use App\Support\AppLock;
use App\Support\UploadStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\UsesTemporaryDownloadDirectory;
use Tests\TestCase;

class FileConvertTest extends TestCase
{
    use RefreshDatabase, UsesTemporaryDownloadDirectory;

    private const REMOTE = ['REMOTE_ADDR' => '192.168.1.20'];

    /** @var list<string> */
    private array $uploads = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDownloadDirectory();
        $this->beforeApplicationDestroyed(function () {
            foreach ($this->uploads as $id) {
                app(UploadStore::class)->delete($id);
            }
        });
    }

    /**
     * ffprobe describing a video with sound and tags (or without sound)
     */
    private function fakeProbe(bool $audio = true): void
    {
        Process::fake(function (PendingProcess $process) use ($audio) {
            if ($process->command[0] !== 'ffprobe') {
                return Process::result();
            }

            return json_encode([
                'format' => ['duration' => '215.4', 'bit_rate' => '1500000', 'tags' => ['TITLE' => 'Tum Hi Ho', 'ARTIST' => 'Arijit Singh', 'date' => '2013-04-05']],
                'streams' => array_values(array_filter([
                    ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264', 'disposition' => ['attached_pic' => 0]],
                    $audio ? ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac', 'bit_rate' => '128000'] : null,
                ])),
            ]);
        });
    }

    private function chunk(string $id, int $offset, string $bytes, array $server = [])
    {
        return $this->call('POST', "/api/files/uploads/{$id}/chunk?offset={$offset}", [], [], [], $server + [
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_ACCEPT' => 'application/json',
        ], $bytes);
    }

    private function upload(string $name = 'clip.mp4', string $bytes = 'video-bytes-123'): string
    {
        $id = $this->postJson('/api/files/uploads', ['name' => $name, 'size' => strlen($bytes)])->assertCreated()->json('id');
        $this->uploads[] = $id;
        $this->chunk($id, 0, $bytes)->assertOk();

        return $id;
    }

    public function test_options_tell_whether_files_on_this_computer_can_be_used(): void
    {
        $this->getJson('/api/files/options')->assertOk()->assertJson(['local_files' => true]);
        $this->withServerVariables(self::REMOTE)->getJson('/api/files/options')->assertOk()->assertJson(['local_files' => false]);
    }

    public function test_an_upload_arrives_in_pieces_and_is_described_at_the_end(): void
    {
        $this->fakeProbe();
        $id = $this->postJson('/api/files/uploads', ['name' => 'Tum Hi Ho.mp4', 'size' => 10])->assertCreated()->json('id');
        $this->uploads[] = $id;

        $this->chunk($id, 0, 'abcde')->assertOk()->assertJson(['received' => 5, 'done' => false]);
        // A piece sent again (retry) replaces what came after its offset
        $this->chunk($id, 0, 'abcde')->assertOk()->assertJson(['received' => 5]);
        $this->chunk($id, 5, 'fghij')
            ->assertOk()
            ->assertJson([
                'done' => true,
                'file' => [
                    'name' => 'Tum Hi Ho.mp4',
                    'duration' => 215,
                    'has_video' => true,
                    'codec' => 'aac',
                    'bitrate' => 128,
                    'title' => 'Arijit Singh - Tum Hi Ho',
                    'artist' => 'Arijit Singh',
                    'year' => 2013,
                ],
            ]);

        $this->assertSame('abcdefghij', file_get_contents(app(UploadStore::class)->path($id)));
    }

    public function test_a_piece_beyond_what_was_received_is_refused(): void
    {
        $id = $this->postJson('/api/files/uploads', ['name' => 'a.mp4', 'size' => 10])->json('id');
        $this->uploads[] = $id;

        $this->chunk($id, 5, 'fghij')->assertStatus(409)->assertJson(['received' => 0]);
    }

    public function test_a_file_without_sound_is_rejected_and_removed(): void
    {
        $this->fakeProbe(audio: false);
        $id = $this->postJson('/api/files/uploads', ['name' => 'silent.mp4', 'size' => 3])->json('id');
        $this->uploads[] = $id;

        $this->chunk($id, 0, 'abc')->assertStatus(422)->assertJson(['message' => 'This video has no sound to convert.']);
        $this->assertFalse(app(UploadStore::class)->exists($id));
    }

    public function test_uploads_above_the_size_limit_are_refused(): void
    {
        config(['youtube-converter.max_upload_mb' => 1]);

        $this->postJson('/api/files/uploads', ['name' => 'film.mkv', 'size' => 2 * 1024 * 1024])
            ->assertStatus(422)
            ->assertJsonValidationErrors('size');
    }

    public function test_an_uploaded_file_is_queued_for_conversion_once(): void
    {
        $this->fakeProbe();
        $id = $this->upload('Tum Hi Ho.mp4');
        Queue::fake();

        $response = $this->postJson('/api/files/convert', [
            'items' => [['upload_id' => $id, 'title' => 'Arijit Singh - Tum Hi Ho', 'artist' => 'Arijit Singh']],
            'format' => 'mp3',
            'quality' => '320',
            'normalize_volume' => true,
        ])->assertCreated()->assertJsonPath('downloads.0.source', 'upload');

        $download = Download::findOrFail($response->json('downloads.0.id'));
        $this->assertSame('Tum Hi Ho.mp4', $download->source_name);
        $this->assertSame(app(UploadStore::class)->path($id), $download->source_path);
        $this->assertTrue($download->normalize_volume);
        $this->assertNull($download->youtube_id);
        // The path on disk is not sent to the browser
        $this->assertArrayNotHasKey('source_path', $response->json('downloads.0'));
        Queue::assertPushed(ConvertLocalFileJob::class, fn ($job) => $job->download->is($download));

        $this->postJson('/api/files/convert', ['items' => [['upload_id' => $id]], 'format' => 'mp3', 'quality' => '320'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.upload_id');
    }

    public function test_files_on_this_computer_can_be_converted_only_from_this_computer(): void
    {
        $file = $this->makeAudioFile('Holiday video.mp4');
        Queue::fake();

        $this->withServerVariables(self::REMOTE)
            ->postJson('/api/files/convert', ['items' => [['path' => $file]], 'format' => 'mp3', 'quality' => '320'])
            ->assertForbidden();
        $this->withServerVariables(self::REMOTE)->getJson('/api/files/browse?path=' . urlencode($this->downloadDir))->assertForbidden();
        $this->withServerVariables(self::REMOTE)->postJson('/api/files/inspect', ['paths' => [$file]])->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/files/convert', ['items' => [['path' => $file]], 'format' => 'flac', 'quality' => '320'])
            ->assertCreated()
            ->assertJsonPath('downloads.0.source', 'local')
            ->assertJsonPath('downloads.0.title', 'Holiday video');

        Queue::assertPushed(ConvertLocalFileJob::class);
    }

    public function test_a_password_allows_files_on_the_computer_from_other_devices(): void
    {
        config(['youtube-converter.password' => 'secret']);
        $cookie = collect($this->postJson('/api/lock/unlock', ['password' => 'secret'])->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === AppLock::COOKIE);

        $this->withServerVariables(self::REMOTE)
            ->withCredentials()
            ->withUnencryptedCookie(AppLock::COOKIE, $cookie->getValue())
            ->getJson('/api/files/options')
            ->assertOk()
            ->assertJson(['local_files' => true]);
    }

    public function test_missing_or_duplicate_files_queue_nothing(): void
    {
        $file = $this->makeAudioFile('a.mp3');
        Queue::fake();

        $this->postJson('/api/files/convert', ['items' => [['path' => $file], ['path' => $this->downloadDir . '/gone.mp4']], 'format' => 'mp3', 'quality' => '320'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.1.path');

        $this->postJson('/api/files/convert', ['items' => [['path' => $file], ['path' => $file]], 'format' => 'mp3', 'quality' => '320'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.1');

        $this->assertSame(0, Download::count());
        Queue::assertNothingPushed();
    }

    public function test_browsing_lists_folders_and_only_video_and_audio_files(): void
    {
        File::ensureDirectoryExists($this->downloadDir . '/Concerts');
        $this->makeAudioFile('song.flac');
        $this->makeAudioFile('clip.MP4');
        $this->makeAudioFile('notes.txt');

        $response = $this->getJson('/api/files/browse?path=' . urlencode($this->downloadDir))->assertOk();

        $this->assertSame(['Concerts'], array_column($response->json('folders'), 'name'));
        $this->assertSame(['clip.MP4', 'song.flac'], array_column($response->json('files'), 'name'));
        $this->assertSame(['video', 'audio'], array_column($response->json('files'), 'kind'));
    }

    public function test_the_original_is_kept_until_the_user_deletes_it(): void
    {
        $original = $this->makeAudioFile('original.mp4');
        $converted = $this->makeAudioFile('original.mp3');
        $download = Download::factory()->create([
            'source' => 'local', 'source_path' => $original, 'source_name' => 'original.mp4',
            'youtube_url' => null, 'youtube_id' => null,
            'status' => 'completed', 'file_path' => $converted,
        ]);

        $this->getJson("/api/downloads/{$download->id}")->assertJsonPath('download.original_available', true);
        $this->withServerVariables(self::REMOTE)->deleteJson("/api/downloads/{$download->id}/original")->assertForbidden();
        $this->assertFileExists($original);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->deleteJson("/api/downloads/{$download->id}/original")
            ->assertOk()
            ->assertJsonPath('download.original_available', false);
        $this->assertFileDoesNotExist($original);
        $this->assertFileExists($converted);
    }

    public function test_deleting_a_conversion_keeps_the_original(): void
    {
        $original = $this->makeAudioFile('keep me.mp4');
        $download = Download::factory()->create([
            'source' => 'local', 'source_path' => $original,
            'status' => 'completed', 'file_path' => $this->makeAudioFile('keep me.mp3'),
        ]);

        $this->deleteJson("/api/downloads/{$download->id}")->assertOk();

        $this->assertFileExists($original);
    }

    public function test_only_originals_on_this_computer_can_be_deleted(): void
    {
        $download = Download::factory()->create(['source' => 'youtube', 'status' => 'completed']);

        $this->deleteJson("/api/downloads/{$download->id}/original")->assertStatus(422);
    }

    public function test_history_can_be_filtered_by_source(): void
    {
        Download::factory()->create(['source' => 'youtube', 'status' => 'completed', 'title' => 'From YouTube', 'completed_at' => now()]);
        Download::factory()->create(['source' => 'upload', 'status' => 'completed', 'title' => 'Uploaded', 'completed_at' => now()]);
        Download::factory()->create(['source' => 'local', 'status' => 'completed', 'title' => 'On PC', 'completed_at' => now()]);

        $this->assertSame(['From YouTube'], array_column($this->getJson('/api/history?source=youtube')->json('data'), 'title'));
        $this->assertEqualsCanonicalizing(['Uploaded', 'On PC'], array_column($this->getJson('/api/history?source=files')->json('data'), 'title'));
        $this->assertCount(3, $this->getJson('/api/history')->json('data'));
    }

    public function test_retrying_a_failed_conversion_converts_again(): void
    {
        Queue::fake();
        $download = Download::factory()->failed()->create(['source' => 'local', 'source_path' => $this->makeAudioFile('a.mp4')]);
        $youtube = Download::factory()->failed()->create();

        $this->postJson("/api/downloads/{$download->id}/retry")->assertOk();
        $this->postJson("/api/downloads/{$youtube->id}/retry")->assertOk();

        Queue::assertPushed(ConvertLocalFileJob::class, fn ($job) => $job->download->is($download));
        Queue::assertPushed(DownloadYoutubeAudioJob::class, fn ($job) => $job->download->is($youtube));
    }

    public function test_cancelling_an_upload_removes_it(): void
    {
        $id = $this->postJson('/api/files/uploads', ['name' => 'a.mp4', 'size' => 10])->json('id');

        $this->deleteJson("/api/files/uploads/{$id}")->assertOk();

        $this->assertFalse(app(UploadStore::class)->exists($id));
    }

    public function test_old_unused_uploads_are_pruned(): void
    {
        $store = app(UploadStore::class);
        $old = $store->create('old.mp4', 5);
        $waiting = $store->create('waiting.mp4', 5);
        $fresh = $store->create('fresh.mp4', 5);
        $this->uploads = [$old, $waiting, $fresh];

        foreach ([$old, $waiting] as $id) {
            foreach (glob(dirname($store->path($id)) . '/*') as $file) {
                touch($file, time() - UploadStore::KEEP_SECONDS - 60);
            }
        }

        $this->assertSame(1, $store->prune([$store->path($waiting)]));
        $this->assertFalse($store->exists($old));
        $this->assertTrue($store->exists($waiting));
        $this->assertTrue($store->exists($fresh));
    }
}
