<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\UsesTemporaryDownloadDirectory;
use Tests\TestCase;

class FolderPickerTest extends TestCase
{
    use RefreshDatabase, UsesTemporaryDownloadDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDownloadDirectory();
    }

    private function native(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    public function test_it_lists_the_folders_inside_a_folder(): void
    {
        File::ensureDirectoryExists($this->downloadDir . '/Music 10');
        File::ensureDirectoryExists($this->downloadDir . '/Music 2');
        File::ensureDirectoryExists($this->downloadDir . '/.hidden');
        File::put($this->downloadDir . '/song.mp3', 'audio');

        $response = $this->getJson('/api/settings/folders?path=' . urlencode($this->downloadDir))->assertOk();

        $this->assertSame($this->native(realpath($this->downloadDir)), $response->json('path'));
        $this->assertSame(['Music 2', 'Music 10'], array_column($response->json('folders'), 'name'));
        $this->assertSame($this->native(realpath($this->downloadDir) . '/Music 2'), $response->json('folders.0.path'));
        $this->assertSame($this->native(dirname(realpath($this->downloadDir))), $response->json('parent'));
        $this->assertTrue($response->json('writable'));
        $this->assertContains('Download folder', array_column($response->json('places'), 'label'));
    }

    public function test_without_a_path_it_opens_the_download_folder(): void
    {
        $this->getJson('/api/settings/folders')
            ->assertOk()
            ->assertJsonPath('path', $this->native(realpath($this->downloadDir)));
    }

    public function test_a_missing_folder_opens_its_nearest_existing_parent(): void
    {
        $this->getJson('/api/settings/folders?path=' . urlencode($this->downloadDir . '/not/there/yet'))
            ->assertOk()
            ->assertJsonPath('path', $this->native(realpath($this->downloadDir)));
    }

    public function test_the_root_has_no_parent(): void
    {
        $root = PHP_OS_FAMILY === 'Windows' ? substr(base_path(), 0, 3) : '/';

        $response = $this->getJson('/api/settings/folders?path=' . urlencode($root))->assertOk();

        $this->assertNull($response->json('parent'));
        $this->assertSame($this->native($root), $response->json('path'));
    }

    public function test_it_creates_a_new_folder(): void
    {
        $this->postJson('/api/settings/folders', ['path' => $this->downloadDir, 'name' => 'My Music'])
            ->assertCreated()
            ->assertJsonPath('path', $this->native(realpath($this->downloadDir) . '/My Music'));

        $this->assertDirectoryExists($this->downloadDir . '/My Music');
    }

    public function test_folder_names_that_could_escape_are_rejected(): void
    {
        foreach (['../outside', 'a/b', 'a\\b', '..', 'bad:name', ''] as $name) {
            $this->postJson('/api/settings/folders', ['path' => $this->downloadDir, 'name' => $name])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('name');
        }

        $this->assertDirectoryDoesNotExist(dirname($this->downloadDir) . '/outside');
    }

    public function test_creating_inside_a_missing_folder_fails(): void
    {
        $this->postJson('/api/settings/folders', ['path' => $this->downloadDir . '/missing', 'name' => 'New'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }
}
