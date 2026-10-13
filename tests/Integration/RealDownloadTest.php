<?php

namespace Tests\Integration;

use App\Models\Download;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\UsesTemporaryDownloadDirectory;
use Tests\TestCase;

/**
 * Downloads real videos with the installed yt-dlp and ffmpeg. Needs network access and
 * takes a minute, so it is excluded from the default run:
 *
 *     php artisan test --group=integration
 */
#[Group('integration')]
class RealDownloadTest extends TestCase
{
    use RefreshDatabase, UsesTemporaryDownloadDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        Process::preventStrayProcesses(false);

        $this->setUpDownloadDirectory();
        // Run the queued job inside the HTTP request
        config(['queue.default' => 'sync']);
    }

    public static function videos(): array
    {
        return [
            'LADY, mp3 (jpg thumbnail)' => [
                'https://www.youtube.com/watch?v=TpHlTBRLHgs', 'mp3', '192', 'D-Sturb - LADY (Official Music Video)', 177,
            ],
            'Nothing Like The Oldschool, mp3 (webp thumbnail)' => [
                'https://www.youtube.com/watch?v=MnH03XLqeRs', 'mp3', '320', 'D-Sturb & Sefa - Nothing Like The Oldschool', 236,
            ],
            'LADY, flac' => [
                'https://www.youtube.com/watch?v=TpHlTBRLHgs', 'flac', '320', 'D-Sturb - LADY (Official Music Video)', 177,
            ],
        ];
    }

    #[DataProvider('videos')]
    public function test_a_real_video_is_downloaded_converted_and_tagged(
        string $url,
        string $format,
        string $quality,
        string $expectedTitle,
        int $expectedDuration,
    ): void {
        // Same request the UI makes: no title, so the job has to fetch it
        $this->postJson('/api/downloads', [
            'youtube_url' => $url,
            'format' => $format,
            'quality' => $quality,
        ])->assertCreated();

        $download = Download::sole();
        $this->assertSame('completed', $download->status, (string) $download->error_message);
        $this->assertSame('Completed', $download->status_text);
        $this->assertSame($expectedTitle, $download->title);
        $this->assertSame($expectedDuration, $download->duration);
        $this->assertStringEndsWith('.' . $format, $download->file_path);
        $this->assertFileExists($download->file_path);
        $this->assertSame(filesize($download->file_path), $download->file_size);

        // Inspect the produced file with ffprobe
        $probe = json_decode(Process::run([
            'ffprobe', '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $download->file_path,
        ])->throw()->output(), true);

        $tags = array_change_key_case($probe['format']['tags'] ?? []);
        $audio = collect($probe['streams'])->firstWhere('codec_type', 'audio');
        $cover = collect($probe['streams'])->firstWhere('codec_type', 'video');

        $this->assertSame($format === 'mp3' ? 'mp3' : 'flac', $audio['codec_name']);
        $this->assertEqualsWithDelta($expectedDuration, (float) $probe['format']['duration'], 2);
        $this->assertSame($expectedTitle, $tags['title'] ?? null, 'Title tag missing');
        $this->assertNotEmpty($tags['artist'] ?? null, 'Artist tag missing');
        $this->assertNotNull($cover, 'Cover art missing');
        $this->assertSame('mjpeg', $cover['codec_name']);
        $this->assertSame(1, $cover['disposition']['attached_pic']);

        if ($format === 'mp3') {
            $this->assertEqualsWithDelta((int) $quality * 1000, (int) $audio['bit_rate'], 8000);
        }

        // The file can be downloaded through the API
        $response = $this->get($download->download_url);
        $response->assertOk();
        $this->assertSame(filesize($download->file_path), strlen($response->streamedContent()));
    }
}
