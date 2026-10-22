<?php

namespace Tests\Concerns;

use App\Models\Setting;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Points the app's download and upload directories at throwaway folders for the test.
 */
trait UsesTemporaryDownloadDirectory
{
    protected string $downloadDir;

    protected function setUpDownloadDirectory(): void
    {
        $this->downloadDir = storage_path('framework/testing/downloads-' . Str::random(8));
        File::ensureDirectoryExists($this->downloadDir);

        config(['youtube-converter.download_path' => $this->downloadDir]);
        Setting::set('download_path', $this->downloadDir);
        Setting::set('min_disk_space_mb', 0);

        // Uploads for conversion too, so tests never touch the app's real uploads
        $uploadDir = storage_path('framework/testing/uploads-' . Str::random(8));
        config(['youtube-converter.upload_path' => $uploadDir]);

        $this->beforeApplicationDestroyed(function () use ($uploadDir) {
            File::deleteDirectory($this->downloadDir);
            File::deleteDirectory($uploadDir);
        });
    }

    /**
     * Create a small file in the download directory and return its path.
     */
    protected function makeAudioFile(string $name = 'song.mp3', string $contents = 'fake-audio-bytes'): string
    {
        $path = $this->downloadDir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }
}
