<?php

namespace Tests\Feature\Api;

use App\Models\Download;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesTemporaryDownloadDirectory;
use Tests\TestCase;

class ZipDownloadTest extends TestCase
{
    use RefreshDatabase, UsesTemporaryDownloadDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDownloadDirectory();
        config(['app.name' => 'AudioFetch']);
    }

    /**
     * Entries of a ZIP response: name → contents
     */
    private function zipEntries($response): array
    {
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();
        // Tests never send the response, so remove the temporary ZIP here
        unlink($path);

        return $entries;
    }

    public function test_selected_songs_are_zipped_with_their_titles(): void
    {
        $a = Download::factory()->completed($this->makeAudioFile('a.mp3', 'first song'))->create(['title' => 'First Song', 'format' => 'mp3']);
        $b = Download::factory()->completed($this->makeAudioFile('b.flac', 'second song'))->create(['title' => 'Second: Song?', 'format' => 'flac']);
        Download::factory()->completed($this->makeAudioFile('c.mp3', 'not selected'))->create(['title' => 'Other']);

        $response = $this->get("/api/downloads/zip?ids={$b->id},{$a->id}")->assertOk();

        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('AudioFetch - 2 songs', $response->headers->get('Content-Disposition'));
        // In the order they were selected, named like the single-file download
        $this->assertSame(['Second Song.flac' => 'second song', 'First Song.mp3' => 'first song'], $this->zipEntries($response));
    }

    public function test_songs_with_the_same_title_get_numbered(): void
    {
        $ids = collect(['one', 'two', 'three'])
            ->map(fn ($text) => Download::factory()->completed($this->makeAudioFile("{$text}.mp3", $text))->create(['title' => 'Same', 'format' => 'mp3'])->id)
            ->implode(',');

        $entries = $this->zipEntries($this->get("/api/downloads/zip?ids={$ids}")->assertOk());

        $this->assertSame(['Same.mp3', 'Same (2).mp3', 'Same (3).mp3'], array_keys($entries));
    }

    public function test_missing_files_and_unfinished_downloads_are_left_out(): void
    {
        $ok = Download::factory()->completed($this->makeAudioFile('ok.mp3', 'ok'))->create(['title' => 'Kept', 'format' => 'mp3']);
        $gone = Download::factory()->completed()->create(['title' => 'Gone']);
        $pending = Download::factory()->create(['title' => 'Pending']);

        $entries = $this->zipEntries($this->get("/api/downloads/zip?ids={$ok->id},{$gone->id},{$pending->id}")->assertOk());

        $this->assertSame(['Kept.mp3'], array_keys($entries));
    }

    public function test_bad_requests_are_explained(): void
    {
        $gone = Download::factory()->completed()->create();

        $this->getJson('/api/downloads/zip')->assertUnprocessable();
        $this->getJson('/api/downloads/zip?ids=abc,-1')->assertUnprocessable();
        $this->getJson('/api/downloads/zip?ids=' . implode(',', range(1, 501)))->assertUnprocessable();
        $this->getJson("/api/downloads/zip?ids={$gone->id}")->assertNotFound()->assertJsonPath('message', 'None of these files exist anymore.');
    }

    public function test_the_zip_needs_the_password_when_the_app_is_locked(): void
    {
        config(['youtube-converter.password' => 'secret']);
        $download = Download::factory()->completed($this->makeAudioFile())->create();

        $this->getJson("/api/downloads/zip?ids={$download->id}")->assertUnauthorized();
    }

    public function test_single_downloads_still_work(): void
    {
        $download = Download::factory()->completed($this->makeAudioFile('x.mp3', 'audio'))->create(['title' => 'Single', 'format' => 'mp3']);

        $this->get("/api/downloads/{$download->id}/stream")
            ->assertOk()
            ->assertDownload('Single.mp3');
    }
}
