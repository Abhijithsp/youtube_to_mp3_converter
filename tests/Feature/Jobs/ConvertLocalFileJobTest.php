<?php

namespace Tests\Feature\Jobs;

use App\Exceptions\AudioConversionException;
use App\Jobs\ConvertLocalFileJob;
use App\Models\Download;
use App\Models\Setting;
use App\Support\UploadStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\Concerns\UsesTemporaryDownloadDirectory;
use Tests\TestCase;

class ConvertLocalFileJobTest extends TestCase
{
    use RefreshDatabase, UsesTemporaryDownloadDirectory;

    /** @var array<int, array> Commands the fake tools received */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDownloadDirectory();
        Setting::set('fetch_lyrics', false);
    }

    /**
     * Fake ffprobe (a video with sound) and ffmpeg (writes its output file)
     */
    private function fakeTools(bool $video = true, ?\Closure $ffmpeg = null): void
    {
        Process::fake(function (PendingProcess $process) use ($video, $ffmpeg) {
            $command = $process->command;
            $this->commands[] = $command;

            if ($command[0] === 'ffprobe') {
                return json_encode([
                    'format' => ['duration' => '95.0'],
                    'streams' => array_values(array_filter([
                        $video ? ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264', 'disposition' => ['attached_pic' => 0]] : null,
                        ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac'],
                    ])),
                ]);
            }

            if ($command[0] === 'ffmpeg' && in_array('s16le', $command, true)) {
                return Process::result(output: pack('v*', ...range(0, 32000, 50)));
            }

            if ($command[0] === 'ffmpeg') {
                if ($ffmpeg) {
                    return $ffmpeg($command);
                }
                file_put_contents(end($command), 'ffmpeg-output');

                return Process::result();
            }

            throw new RuntimeException('Unexpected command: ' . implode(' ', $command));
        });
    }

    private function runJob(Download $download): void
    {
        app()->call([new ConvertLocalFileJob($download), 'handle']);
    }

    public function test_a_file_on_this_computer_is_converted_and_the_original_is_kept(): void
    {
        $this->fakeTools();
        $original = $this->makeAudioFile('Holiday.mp4', 'original-video');
        $download = Download::factory()->create([
            'source' => 'local', 'source_path' => $original, 'source_name' => 'Holiday.mp4',
            'youtube_url' => null, 'youtube_id' => null,
            'title' => 'Arijit Singh - Tum Hi Ho', 'artist' => 'Arijit Singh',
            'format' => 'mp3', 'quality' => '256',
        ]);

        $this->runJob($download);
        $download->refresh();

        $this->assertSame('completed', $download->status);
        $this->assertSame(100, $download->progress);
        $this->assertSame($this->downloadDir . '/Arijit Singh - Tum Hi Ho.mp3', $download->file_path);
        $this->assertFileExists($download->file_path);
        $this->assertSame(95, $download->duration);
        $this->assertNotEmpty($download->waveform);

        // The original is untouched, and can be deleted later if the user wants
        $this->assertSame('original-video', file_get_contents($original));
        $this->assertSame($original, $download->source_path);
        $this->assertTrue($download->original_available);

        // Converted from the original file, at the chosen bitrate
        $convert = collect($this->commands)->first(fn ($c) => $c[0] === 'ffmpeg' && in_array('libmp3lame', $c, true));
        $this->assertSame($original, $convert[array_search('-i', $convert, true) + 1]);
        $this->assertContains('256k', $convert);

        // A frame of the video became the cover
        $this->assertStringStartsWith('/api/downloads/' . $download->id . '/cover', $download->thumbnail_url);
        $this->assertFileExists($download->coverPath());

        $download->delete();
        $this->assertFileDoesNotExist($download->coverPath());
    }

    public function test_an_upload_is_removed_after_its_conversion(): void
    {
        $this->fakeTools(video: false);
        $store = app(UploadStore::class);
        $id = $store->create('voice note.m4a', 5);
        file_put_contents($store->path($id), 'audio');
        $download = Download::factory()->create([
            'source' => 'upload', 'source_path' => $store->path($id), 'source_name' => 'voice note.m4a',
            'title' => 'voice note', 'artist' => null, 'format' => 'wav',
        ]);

        $this->runJob($download);
        $download->refresh();

        $this->assertSame('completed', $download->status);
        $this->assertSame($this->downloadDir . '/voice note.wav', $download->file_path);
        $this->assertFalse($store->exists($id));
        $this->assertNull($download->source_path);
        $this->assertFalse($download->original_available);
        // No video stream: no cover
        $this->assertNull($download->thumbnail_url);
    }

    public function test_an_original_in_the_download_folder_is_never_overwritten(): void
    {
        $this->fakeTools(video: false);
        $original = $this->makeAudioFile('Song.mp3', 'original');
        $download = Download::factory()->create([
            'source' => 'local', 'source_path' => $original, 'title' => 'Song', 'format' => 'mp3',
        ]);

        $this->runJob($download);

        $this->assertSame($this->downloadDir . '/Song (1).mp3', $download->fresh()->file_path);
        $this->assertSame('original', file_get_contents($original));
    }

    public function test_a_missing_file_fails_with_a_clear_message(): void
    {
        $this->fakeTools();
        $download = Download::factory()->create([
            'source' => 'local', 'source_path' => $this->downloadDir . '/moved.mp4', 'source_name' => 'moved.mp4',
        ]);

        try {
            $this->runJob($download);
            $this->fail('The job should have failed');
        } catch (AudioConversionException $e) {
            $this->assertSame('The file is no longer there.', $e->getMessage());
        }

        (new ConvertLocalFileJob($download))->failed($e);
        $this->assertSame('failed', $download->fresh()->status);
        $this->assertSame('The file is no longer there.', $download->fresh()->error_message);
    }

    public function test_a_failed_conversion_leaves_no_half_written_file(): void
    {
        $original = $this->makeAudioFile('Broken.mkv', 'original');
        $this->fakeTools(ffmpeg: function (array $command) {
            file_put_contents(end($command), 'partial');

            return Process::result(errorOutput: 'Invalid data found when processing input', exitCode: 1);
        });
        $download = Download::factory()->create(['source' => 'local', 'source_path' => $original, 'title' => 'Broken']);

        try {
            $this->runJob($download);
        } catch (AudioConversionException $e) {
            // expected
        }

        $this->assertFileDoesNotExist($this->downloadDir . '/Broken.mp3');
        $this->assertFileExists($original);
    }

    public function test_a_cancelled_conversion_stops(): void
    {
        $this->fakeTools();
        $download = Download::factory()->cancelled()->create([
            'source' => 'local', 'source_path' => $this->makeAudioFile('a.mp4'),
        ]);

        $this->runJob($download);

        $this->assertSame('cancelled', $download->fresh()->status);
        $this->assertSame([], $this->commands);
    }
}
