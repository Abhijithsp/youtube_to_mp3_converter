<?php

namespace Tests\Unit\Services;

use App\Services\MetadataService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class MetadataServiceTest extends TestCase
{
    private MetadataService $service;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MetadataService();
        $this->dir = storage_path('framework/testing/metadata');
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /**
     * Fake ffmpeg: writes the output file (the last argument) with the given contents.
     */
    private function fakeFfmpeg(string $contents = 'tagged'): void
    {
        Process::fake(function (PendingProcess $process) use ($contents) {
            file_put_contents(end($process->command), $contents);

            return Process::result();
        });
    }

    public function test_titles_with_spaces_and_quotes_are_passed_as_single_arguments(): void
    {
        $this->fakeFfmpeg();
        $file = $this->dir . '/song.mp3';
        file_put_contents($file, 'original');

        $this->service->tagFile($file, [
            'title' => 'D-Sturb - LADY (Official Music Video)',
            'artist' => 'Q-dance "Music"',
            'album' => null,
        ]);

        Process::assertRan(function (PendingProcess $process) {
            return in_array('title=D-Sturb - LADY (Official Music Video)', $process->command, true)
                && in_array('artist=Q-dance "Music"', $process->command, true)
                && !collect($process->command)->contains(fn ($arg) => str_starts_with($arg, 'album='));
        });

        // The tagged temp file replaced the original
        $this->assertSame('tagged', file_get_contents($file));
        $this->assertFileDoesNotExist($this->dir . '/song_temp.mp3');
    }

    public function test_tagging_without_metadata_does_nothing(): void
    {
        Process::fake();
        $file = $this->dir . '/song.mp3';
        file_put_contents($file, 'original');

        $this->assertTrue($this->service->tagFile($file, ['title' => '', 'artist' => null]));

        Process::assertNothingRan();
    }

    public function test_failed_tagging_keeps_the_original_file_and_throws(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'boom', exitCode: 1)]);
        $file = $this->dir . '/song.mp3';
        file_put_contents($file, 'original');

        try {
            $this->service->tagFile($file, ['title' => 'Song']);
            $this->fail('Expected an exception');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Metadata tagging failed', $e->getMessage());
        }

        $this->assertSame('original', file_get_contents($file));
    }

    public function test_mp3_cover_art_is_reencoded_to_jpeg_with_id3v2(): void
    {
        $this->fakeFfmpeg('with-cover');
        $audio = $this->dir . '/song.mp3';
        $thumb = $this->dir . '/thumb';
        file_put_contents($audio, 'audio');
        file_put_contents($thumb, 'webp-image');

        $this->assertTrue($this->service->attachThumbnail($audio, $thumb));

        Process::assertRan(fn (PendingProcess $process) => in_array('mjpeg', $process->command, true)
            && in_array('attached_pic', $process->command, true)
            && in_array('-id3v2_version', $process->command, true));
        $this->assertSame('with-cover', file_get_contents($audio));
    }

    public function test_flac_cover_art_does_not_use_id3_options(): void
    {
        $this->fakeFfmpeg();
        $audio = $this->dir . '/song.flac';
        $thumb = $this->dir . '/thumb';
        file_put_contents($audio, 'audio');
        file_put_contents($thumb, 'jpeg');

        $this->assertTrue($this->service->attachThumbnail($audio, $thumb));

        Process::assertRan(fn (PendingProcess $process) => !in_array('-id3v2_version', $process->command, true));
    }

    public function test_failed_cover_art_is_not_fatal(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);
        $audio = $this->dir . '/song.mp3';
        $thumb = $this->dir . '/thumb';
        file_put_contents($audio, 'audio');
        file_put_contents($thumb, 'jpeg');

        $this->assertFalse($this->service->attachThumbnail($audio, $thumb));
        $this->assertSame('audio', file_get_contents($audio));
    }

    public function test_thumbnail_download(): void
    {
        Http::fake([
            'i.ytimg.com/vi/ok/*' => Http::response('image-bytes'),
            'i.ytimg.com/vi/missing/*' => Http::response('', 404),
        ]);

        $path = $this->dir . '/thumb';

        $this->assertTrue($this->service->downloadThumbnail('https://i.ytimg.com/vi/ok/maxresdefault.jpg', $path));
        $this->assertSame('image-bytes', file_get_contents($path));

        $this->assertFalse($this->service->downloadThumbnail('https://i.ytimg.com/vi/missing/maxresdefault.jpg', $this->dir . '/other'));
    }

    public function test_metadata_is_extracted_from_youtube_info(): void
    {
        $this->assertSame([
            'title' => 'LADY',
            'artist' => 'Q-dance Music',
            'album' => null,
            'year' => '2019',
            'genre' => 'Music',
        ], $this->service->extractMetadataFromYouTube([
            'title' => 'LADY',
            'uploader' => 'Q-dance Music',
            'upload_date' => '20190412',
        ]));
    }
}
