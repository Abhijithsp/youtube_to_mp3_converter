<?php

namespace Tests\Feature\Api;

use App\Models\Download;
use App\Support\FileExplorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesTemporaryDownloadDirectory;
use Tests\TestCase;

class ShowInFolderTest extends TestCase
{
    use RefreshDatabase, UsesTemporaryDownloadDirectory;

    private array $revealed = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDownloadDirectory();

        // Records what would be opened instead of opening real windows
        $revealed = &$this->revealed;
        $this->app->instance(FileExplorer::class, new class($revealed) extends FileExplorer {
            public function __construct(private array &$revealed) {}

            public function reveal(string $file): void
            {
                $this->revealed[] = $file;
            }
        });
    }

    private function skipWithoutDesktop(): void
    {
        if (PHP_OS_FAMILY === 'Linux' && !getenv('DISPLAY') && !getenv('WAYLAND_DISPLAY')) {
            $this->markTestSkipped('No desktop to open a folder on');
        }
    }

    public function test_the_folder_of_the_saved_file_is_opened(): void
    {
        $this->skipWithoutDesktop();
        $file = $this->makeAudioFile('Headhunterz & Vertile ft. Sian Evans - Lost Without You.mp3');
        $download = Download::factory()->completed($file)->create();

        $this->postJson("/api/downloads/{$download->id}/reveal")->assertOk();

        $this->assertSame([$file], $this->revealed);
    }

    public function test_explorer_gets_the_path_in_the_form_it_understands(): void
    {
        // Spaces and "&" must stay inside /select,"..." or Explorer opens Documents
        $this->assertSame(
            'explorer.exe /select,"D:\\Music\\downloads\\Sefa & D-Block - Symphony of Life.mp3"',
            FileExplorer::windowsCommandLine('D:\\Music\\downloads/Sefa & D-Block - Symphony of Life.mp3')
        );
    }

    public function test_browsers_on_other_devices_cannot_open_folders(): void
    {
        $download = Download::factory()->completed($this->makeAudioFile())->create();

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])
            ->postJson("/api/downloads/{$download->id}/reveal")
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])
            ->getJson('/api/history')
            ->assertJsonPath('can_open_folder', false);

        $this->assertSame([], $this->revealed);
    }

    public function test_a_missing_file_is_reported(): void
    {
        $this->skipWithoutDesktop();
        $download = Download::factory()->completed()->create(['file_path' => '/nowhere/gone.mp3']);

        $this->postJson("/api/downloads/{$download->id}/reveal")->assertNotFound();

        $this->assertSame([], $this->revealed);
    }
}
