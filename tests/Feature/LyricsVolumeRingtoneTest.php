<?php

namespace Tests\Feature;

use App\Jobs\DownloadYoutubeAudioJob;
use App\Models\Download;
use App\Services\AudioConverterService;
use App\Services\LyricsService;
use App\Services\MetadataService;
use App\Services\YouTubeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\UsesTemporaryDownloadDirectory;
use Tests\TestCase;

class LyricsVolumeRingtoneTest extends TestCase
{
    use RefreshDatabase, UsesTemporaryDownloadDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDownloadDirectory();
    }

    // ---- Lyrics lookup ------------------------------------------------------

    public function test_lyrics_are_found_by_exact_lookup(): void
    {
        Http::fake(['lrclib.net/api/get*' => Http::response([
            'plainLyrics' => 'Hello',
            'syncedLyrics' => '[00:01.00] Hello',
        ])]);

        $found = (new LyricsService())->find('D-Sturb - LADY', 'D-Sturb', null, 177);

        $this->assertSame(['status' => 'found', 'lyrics' => 'Hello', 'synced' => '[00:01.00] Hello'], $found);
        Http::assertSent(fn ($request) => $request['track_name'] === 'LADY' && $request['artist_name'] === 'D-Sturb');
    }

    public function test_search_is_used_when_the_exact_lookup_misses_and_the_length_must_match(): void
    {
        Http::fake([
            'lrclib.net/api/get*' => Http::response(['message' => 'not found'], 404),
            'lrclib.net/api/search*' => Http::response([
                ['duration' => 300, 'plainLyrics' => 'Wrong version'],
                ['duration' => 179, 'plainLyrics' => '', 'syncedLyrics' => "[00:01.00] Rebuilt\n[00:02.00] from timed lines"],
            ]),
        ]);

        $found = (new LyricsService())->find('LADY', 'D-Sturb', null, 177);

        $this->assertSame('found', $found['status']);
        $this->assertSame("Rebuilt\nfrom timed lines", $found['lyrics']);
    }

    public function test_instrumentals_missing_lyrics_and_outages(): void
    {
        $service = new LyricsService();

        Http::fake(['lrclib.net/api/get*' => Http::response(['instrumental' => true])]);
        $this->assertSame('instrumental', $service->find('Song', 'Artist', null, 100)['status']);

        Http::fake(fn () => Http::response([], 200));
        $this->assertSame('none', (new LyricsService())->find('Unknown song', null)['status']);
    }

    public function test_an_unreachable_service_returns_null_so_it_is_tried_again(): void
    {
        Http::fake(['lrclib.net/*' => Http::response('down', 503)]);

        $this->assertNull((new LyricsService())->find('Song', 'Artist', null, 100));
    }

    // ---- Lyrics in files ----------------------------------------------------

    /**
     * A minimal MP3 as ffmpeg writes it: ID3v2.3 with a TIT2 frame, padding, then audio
     */
    private function fakeMp3(string $path): string
    {
        $title = "\x00Song";
        $frames = 'TIT2' . pack('N', strlen($title)) . "\x00\x00" . $title . str_repeat("\x00", 20);
        $size = strlen($frames);
        $syncsafe = chr(($size >> 21) & 0x7F) . chr(($size >> 14) & 0x7F) . chr(($size >> 7) & 0x7F) . chr($size & 0x7F);
        file_put_contents($path, "ID3\x03\x00\x00" . $syncsafe . $frames . "\xFF\xFBAUDIO-FRAMES");

        return $path;
    }

    public function test_mp3_lyrics_are_written_as_a_real_uslt_frame_and_replaced_not_duplicated(): void
    {
        $file = $this->fakeMp3($this->downloadDir . '/song.mp3');
        $service = new MetadataService();

        $this->assertTrue($service->writeLyrics($file, "Old lyrics"));
        $this->assertTrue($service->writeLyrics($file, "സ്നേഹം\nNew lyrics"));

        $data = file_get_contents($file);
        $this->assertSame(1, substr_count($data, 'USLT'));
        $this->assertStringContainsString(mb_convert_encoding("സ്നേഹം\nNew lyrics", 'UTF-16LE', 'UTF-8'), $data);
        $this->assertStringNotContainsString(mb_convert_encoding('Old lyrics', 'UTF-16LE', 'UTF-8'), $data);
        // Other frames and the audio are untouched
        $this->assertStringContainsString("TIT2", $data);
        $this->assertStringEndsWith("\xFF\xFBAUDIO-FRAMES", $data);
    }

    public function test_flac_lyrics_use_a_vorbis_comment_and_wav_has_none(): void
    {
        Process::fake(function (PendingProcess $process) {
            file_put_contents(end($process->command), 'tagged');

            return Process::result();
        });
        $service = new MetadataService();

        $this->assertTrue($service->writeLyrics($this->makeAudioFile('song.flac'), 'Line'));
        Process::assertRan(fn (PendingProcess $process) => in_array('LYRICS=Line', $process->command, true));

        $this->assertFalse($service->writeLyrics($this->makeAudioFile('song.wav'), 'Line'));
    }

    // ---- ffmpeg commands ----------------------------------------------------

    public function test_volume_levelling_and_silence_removal_share_one_filter_chain(): void
    {
        $command = (new AudioConverterService())->buildCommand('in.webm', 'out.flac', 'flac', 320, [
            'remove_silence' => true,
            'normalize_volume' => true,
            'duration' => 200,
        ]);

        $this->assertSame(1, count(array_keys($command, '-af', true)));
        $filter = $command[array_search('-af', $command, true) + 1];
        $this->assertStringStartsWith('silenceremove=', $filter);
        $this->assertStringEndsWith(',' . AudioConverterService::LOUDNESS_FILTER, $filter);
        $this->assertSame('44100', $command[array_search('-ar', $command, true) + 1]);
    }

    public function test_the_ringtone_command_cuts_fades_and_encodes_mp3(): void
    {
        $command = (new AudioConverterService())->ringtoneCommand('song.flac', 'tone.mp3', 42.5, 30, true, ['title' => 'Song (Ringtone)']);

        $this->assertSame('42.5', $command[array_search('-ss', $command, true) + 1]);
        $this->assertSame('30', $command[array_search('-t', $command, true) + 1]);
        $this->assertSame('afade=t=in:d=0.5,afade=t=out:st=28:d=2', $command[array_search('-af', $command, true) + 1]);
        $this->assertContains('libmp3lame', $command);
        $this->assertContains('title=Song (Ringtone)', $command);
        $this->assertSame('tone.mp3', end($command));

        $noFade = (new AudioConverterService())->ringtoneCommand('song.mp3', 'tone.mp3', 0, 15, false);
        $this->assertNotContains('-af', $noFade);
    }

    public function test_chapters_are_read_from_yt_dlp(): void
    {
        Process::fake(['*' => json_encode(['id' => 'x', 'title' => 'Mix', 'chapters' => [
            ['start_time' => 0, 'end_time' => 90.123, 'title' => 'One'],
            ['start_time' => 90.123, 'end_time' => 90.5, 'title' => 'Too short'],
            ['start_time' => 90.5, 'end_time' => 200, 'title' => ''],
        ]])]);

        $chapters = app(YouTubeService::class)->extractVideoInfo('https://youtu.be/x')['chapters'];

        $this->assertSame([
            ['title' => 'One', 'start' => 0.0, 'end' => 90.12],
            ['title' => 'Part 3', 'start' => 90.5, 'end' => 200.0],
        ], $chapters);

        // One chapter is not worth splitting
        Process::fake(['*' => json_encode(['id' => 'x', 'chapters' => [['start_time' => 0, 'end_time' => 100, 'title' => 'All']]])]);
        $this->assertSame([], app(YouTubeService::class)->extractVideoInfo('https://youtu.be/x')['chapters']);
    }

    // ---- API ----------------------------------------------------------------

    public function test_new_downloads_accept_volume_and_chapter_options(): void
    {
        Queue::fake();
        // An earlier plain download is not reused for a levelled one
        Download::factory()->completed($this->makeAudioFile())->create(['youtube_id' => 'TpHlTBRLHgs', 'format' => 'mp3', 'quality' => '320']);

        $this->postJson('/api/downloads', [
            'youtube_url' => 'https://www.youtube.com/watch?v=TpHlTBRLHgs',
            'format' => 'mp3',
            'quality' => '320',
            'normalize_volume' => true,
            'split_chapters' => true,
        ])->assertCreated()
            ->assertJsonPath('download.normalize_volume', true)
            ->assertJsonPath('download.split_chapters', true);

        Queue::assertPushed(DownloadYoutubeAudioJob::class);

        $this->postJson('/api/downloads', [
            'youtube_url' => 'https://www.youtube.com/watch?v=TpHlTBRLHgs',
            'format' => 'mp3',
            'quality' => '320',
            'split_chapters' => true,
            'trim_start' => 10,
        ])->assertUnprocessable()->assertJsonPath('errors.split_chapters.0', 'Splitting into chapters works on the whole video. Remove the trim first.');
    }

    public function test_the_lyrics_endpoint_looks_up_once_and_then_serves_stored_lyrics(): void
    {
        Http::fake(['lrclib.net/*' => Http::response(['plainLyrics' => 'Hello', 'syncedLyrics' => '[00:01.00] Hello'])]);
        $download = Download::factory()->completed($this->makeAudioFile('song.wav'))->create(['title' => 'Song', 'artist' => 'Artist']);

        $this->getJson("/api/downloads/{$download->id}/lyrics")
            ->assertOk()
            ->assertJson(['status' => 'found', 'lyrics' => 'Hello', 'synced' => '[00:01.00] Hello']);
        $this->getJson("/api/downloads/{$download->id}/lyrics")->assertOk();

        Http::assertSentCount(1);
        $this->assertSame('found', $download->fresh()->lyrics_status);

        $this->getJson("/api/downloads/{$download->id}/lyrics?refresh=1")->assertOk();
        Http::assertSentCount(2);
    }

    public function test_the_lyrics_endpoint_reports_an_unreachable_service(): void
    {
        Http::fake(['lrclib.net/*' => Http::response('down', 502)]);
        $download = Download::factory()->completed($this->makeAudioFile())->create(['title' => 'Song', 'artist' => 'Artist']);

        $this->getJson("/api/downloads/{$download->id}/lyrics")->assertStatus(503);
        $this->assertNull($download->fresh()->lyrics_status);

        $this->getJson('/api/downloads/' . Download::factory()->create()->id . '/lyrics')->assertNotFound();
    }

    public function test_a_ringtone_is_made_from_part_of_a_song(): void
    {
        Process::fake(function (PendingProcess $process) {
            file_put_contents(end($process->command), 'ringtone-mp3');

            return Process::result();
        });
        $download = Download::factory()->completed($this->makeAudioFile('song.flac'))->create([
            'title' => 'D-Sturb - LADY', 'artist' => 'D-Sturb', 'format' => 'flac', 'duration' => 177,
        ]);

        $response = $this->get("/api/downloads/{$download->id}/ringtone?start=160&length=30&fade=1")->assertOk();

        $this->assertSame('audio/mpeg', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('D-Sturb - LADY (Ringtone).mp3', $response->headers->get('Content-Disposition'));
        // Cut short at the end of the song: 177 - 160 = 17 s
        Process::assertRan(fn (PendingProcess $process) => $process->command[array_search('-t', $process->command, true) + 1] === '17'
            && in_array('title=LADY (Ringtone)', $process->command, true));

        @unlink($response->baseResponse->getFile()->getPathname());
    }

    public function test_ringtone_requests_are_checked(): void
    {
        Process::fake();
        $download = Download::factory()->completed($this->makeAudioFile())->create(['duration' => 100]);

        $this->getJson("/api/downloads/{$download->id}/ringtone?start=0&length=41")->assertUnprocessable()->assertJsonValidationErrors('length');
        $this->getJson("/api/downloads/{$download->id}/ringtone?start=0&length=2")->assertUnprocessable();
        $this->getJson("/api/downloads/{$download->id}/ringtone?start=99.5&length=20")->assertUnprocessable()->assertJsonValidationErrors('start');
        $this->getJson('/api/downloads/' . Download::factory()->completed()->create()->id . '/ringtone?start=0&length=20')->assertNotFound();

        Process::assertNothingRan();
    }
}
