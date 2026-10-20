<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Files sent from the browser for conversion. They arrive in pieces (so big phone
 * videos survive slow networks and the single-threaded dev server stays responsive)
 * and are kept only until they are converted.
 *
 * Each upload is a folder: storage/app/uploads/<id>/ with the file and a meta.json.
 */
class UploadStore
{
    /**
     * Size of one piece sent by the browser
     */
    public const CHUNK_BYTES = 8 * 1024 * 1024;

    /**
     * Unfinished or unused uploads are removed after this long
     */
    public const KEEP_SECONDS = 86400;

    public static function root(): string
    {
        return storage_path('app' . DIRECTORY_SEPARATOR . 'uploads');
    }

    /**
     * Start an upload; returns its id
     */
    public function create(string $name, int $size): string
    {
        $id = (string) Str::uuid();
        File::ensureDirectoryExists($this->folder($id));

        // The original name is kept for display; the file on disk gets a safe name
        $extension = strtolower(preg_replace('/[^a-z0-9]/i', '', pathinfo($name, PATHINFO_EXTENSION)));
        $this->writeMeta($id, [
            'name' => $name,
            'size' => $size,
            'file' => 'source' . ($extension !== '' ? ".{$extension}" : ''),
            'used' => false,
        ]);
        touch($this->folder($id) . DIRECTORY_SEPARATOR . $this->meta($id)['file']);

        return $id;
    }

    /**
     * Add a piece at $offset. A piece sent again (the browser retried) replaces what
     * came after its offset. Returns the number of bytes received so far.
     *
     * @param  resource  $stream
     */
    public function append(string $id, int $offset, $stream): int
    {
        $path = $this->path($id);
        $received = filesize($path);

        if ($offset > $received) {
            throw new \InvalidArgumentException("Expected the piece at byte {$received}, got {$offset}.");
        }

        $file = fopen($path, 'c+b');
        ftruncate($file, $offset);
        fseek($file, $offset);
        stream_copy_to_stream($stream, $file);
        fclose($file);

        clearstatcache(true, $path);
        $received = filesize($path);
        $size = $this->meta($id)['size'];

        if ($received > $size) {
            throw new \InvalidArgumentException('The file is larger than announced.');
        }

        return $received;
    }

    public function exists(string $id): bool
    {
        return Str::isUuid($id) && is_file($this->folder($id) . DIRECTORY_SEPARATOR . 'meta.json');
    }

    public function isComplete(string $id): bool
    {
        return $this->exists($id) && filesize($this->path($id)) === $this->meta($id)['size'];
    }

    /**
     * @return array{name: string, size: int, file: string, used: bool}
     */
    public function meta(string $id): array
    {
        return json_decode((string) file_get_contents($this->folder($id) . DIRECTORY_SEPARATOR . 'meta.json'), true);
    }

    /**
     * Mark the upload as taken by a conversion, so it can't be queued twice
     */
    public function markUsed(string $id): void
    {
        $this->writeMeta($id, ['used' => true] + $this->meta($id));
    }

    public function path(string $id): string
    {
        return $this->folder($id) . DIRECTORY_SEPARATOR . $this->meta($id)['file'];
    }

    public function delete(string $id): void
    {
        if (Str::isUuid($id)) {
            File::deleteDirectory($this->folder($id));
        }
    }

    /**
     * Delete the upload a file path belongs to (after its conversion)
     */
    public function deleteFor(?string $path): void
    {
        $root = realpath(self::root());
        $folder = $path ? realpath(dirname($path)) : false;

        if ($root && $folder && dirname($folder) === $root) {
            File::deleteDirectory($folder);
        }
    }

    /**
     * Remove uploads nobody finished or converted, and leftovers of crashed jobs.
     * Uploads still waiting in the queue are kept.
     *
     * @param  list<string>  $keep  paths of files that queued conversions still need
     */
    public function prune(array $keep = []): int
    {
        $keepFolders = array_map(fn ($path) => realpath(dirname($path)) ?: dirname($path), $keep);
        $removed = 0;

        foreach (glob(self::root() . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $folder) {
            // Last activity: the newest file (a piece arriving updates the file, not the folder)
            $touched = max(array_map('filemtime', glob($folder . DIRECTORY_SEPARATOR . '*') ?: [$folder]));

            if (in_array(realpath($folder), $keepFolders, true) || time() - $touched < self::KEEP_SECONDS) {
                continue;
            }

            File::deleteDirectory($folder);
            $removed++;
        }

        return $removed;
    }

    private function folder(string $id): string
    {
        return self::root() . DIRECTORY_SEPARATOR . $id;
    }

    private function writeMeta(string $id, array $meta): void
    {
        file_put_contents($this->folder($id) . DIRECTORY_SEPARATOR . 'meta.json', json_encode($meta));
    }
}
