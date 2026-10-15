<?php

namespace Tests\Feature;

use App\Models\Download;
use App\Services\WaveformService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\UsesTemporaryDownloadDirectory;
use Tests\TestCase;

class WaveformTest extends TestCase
{
    use RefreshDatabase, UsesTemporaryDownloadDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDownloadDirectory();
    }

    /**
     * Raw 16-bit little-endian PCM: $quiet samples of silence, then $loud loud ones.
     */
    private function pcm(int $quiet, int $loud, int $amplitude = 20000): string
    {
        // range(1, 0) would count down, so build the loud part only when there is one
        $loudSamples = $loud > 0 ? array_map(fn ($i) => $i % 2 ? $amplitude : -$amplitude, range(1, $loud)) : [];
        $samples = array_merge(array_fill(0, $quiet, 0), $loudSamples);

        return pack('v*', ...array_map(fn ($s) => $s & 0xFFFF, $samples));
    }

    public function test_peaks_follow_the_loudness_and_are_normalised(): void
    {
        $peaks = (new WaveformService())->peaks($this->pcm(1000, 1000), 10);

        $this->assertSame([0, 0, 0, 0, 0, 100, 100, 100, 100, 100], $peaks);
    }

    public function test_negative_samples_count_as_loud(): void
    {
        // Only negative values: must not be read as huge unsigned numbers or as silence
        $pcm = pack('v*', ...array_fill(0, 100, -10000 & 0xFFFF));

        $this->assertSame(array_fill(0, 4, 100), (new WaveformService())->peaks($pcm, 4));
    }

    public function test_silence_and_too_short_audio(): void
    {
        $service = new WaveformService();

        $this->assertSame([0, 0, 0], $service->peaks($this->pcm(30, 0), 3));
        $this->assertNull($service->peaks($this->pcm(2, 0), 10));
    }

    public function test_generate_decodes_with_ffmpeg_to_low_rate_mono_pcm(): void
    {
        Process::fake(['*' => Process::result(output: $this->pcm(800, 800))]);
        $file = $this->makeAudioFile('Song With Spaces.mp3');

        $peaks = (new WaveformService())->generate($file, 8);

        $this->assertSame([0, 0, 0, 0, 100, 100, 100, 100], $peaks);
        Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'ffmpeg'
            && in_array($file, $process->command, true)
            && in_array('s16le', $process->command, true)
            && $process->command[array_search('-ac', $process->command, true) + 1] === '1');
    }

    public function test_generate_returns_null_when_ffmpeg_fails_or_the_file_is_missing(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'Invalid data found', exitCode: 1)]);
        $service = new WaveformService();

        $this->assertNull($service->generate($this->makeAudioFile('broken.mp3')));
        $this->assertNull($service->generate($this->downloadDir . '/missing.mp3'));
    }

    public function test_the_endpoint_creates_and_stores_a_missing_waveform_once(): void
    {
        Process::fake(['*' => Process::result(output: $this->pcm(2000, 2000))]);
        $download = Download::factory()->completed($this->makeAudioFile())->create(['waveform' => null]);

        $this->getJson("/api/downloads/{$download->id}/waveform")
            ->assertOk()
            ->assertJsonCount(WaveformService::POINTS, 'waveform');

        $this->assertCount(WaveformService::POINTS, $download->fresh()->waveform);

        // Second request: served from the database
        $this->getJson("/api/downloads/{$download->id}/waveform")->assertOk();
        Process::assertRanTimes(fn () => true, 1);
    }

    public function test_the_endpoint_reports_unavailable_audio(): void
    {
        Process::fake();
        $missing = Download::factory()->completed()->create();
        $pending = Download::factory()->create();

        $this->getJson("/api/downloads/{$missing->id}/waveform")->assertNotFound()->assertJsonPath('waveform', null);
        $this->getJson("/api/downloads/{$pending->id}/waveform")->assertNotFound();
    }

    public function test_downloads_include_their_waveform(): void
    {
        $download = Download::factory()->completed($this->makeAudioFile())->create(['waveform' => [10, 50, 100]]);

        $this->getJson('/api/history')->assertJsonPath('data.0.waveform', [10, 50, 100]);
        $this->getJson("/api/downloads/{$download->id}")->assertJsonPath('download.waveform', [10, 50, 100]);
    }

    public function test_the_command_backfills_missing_waveforms(): void
    {
        Process::fake(['*' => Process::result(output: $this->pcm(2000, 2000))]);
        $missing = Download::factory()->completed($this->makeAudioFile('a.mp3'))->create(['waveform' => null]);
        $existing = Download::factory()->completed($this->makeAudioFile('b.mp3'))->create(['waveform' => [1, 2, 3]]);
        Download::factory()->completed()->create(['waveform' => null]); // file is gone: skipped

        $this->artisan('downloads:waveforms')->assertSuccessful();

        $this->assertCount(WaveformService::POINTS, $missing->fresh()->waveform);
        $this->assertSame([1, 2, 3], $existing->fresh()->waveform);
        Process::assertRanTimes(fn () => true, 1);

        $this->artisan('downloads:waveforms')->expectsOutputToContain('already has a waveform')->assertSuccessful();
    }
}
