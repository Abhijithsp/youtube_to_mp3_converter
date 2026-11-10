<?php

namespace App\Support;

use App\Models\Download;
use Illuminate\Support\LazyCollection;

/**
 * Moves finished songs from one download folder to another and updates their saved paths,
 * so History, the player and "Show in folder" follow the files.
 */
class SongMover
{
    /**
     * Finished songs whose file is directly inside $folder
     */
    public function songsIn(string $folder): LazyCollection
    {
        return Download::completed()
            ->whereNotNull('file_path')
            ->lazyById(100)
            ->filter(fn (Download $download) => self::samePath($this->homeOf($download), $folder)
                && is_file($download->file_path));
    }

    /**
     * The download folder a file belongs to; videos sit in its Videos subfolder
     */
    private function homeOf(Download $download): string
    {
        $folder = dirname($download->file_path);

        return $download->is_video ? dirname($folder) : $folder;
    }

    /**
     * Move every song in $from to $to. A name already taken in $to gets " (1)", " (2)", ...
     * A file that cannot be moved (open in another program, no permission) stays where it is.
     *
     * @return array{moved: int, failed: list<string>}
     */
    public function move(string $from, string $to): array
    {
        $moved = 0;
        $failed = [];

        foreach ($this->songsIn($from) as $download) {
            $folder = $download->is_video ? $to . DIRECTORY_SEPARATOR . Download::VIDEO_FOLDER : $to;

            if (!is_dir($folder)) {
                @mkdir($folder, 0755, true);
            }

            $target = self::freeName($folder, basename($download->file_path));

            if (!@rename($download->file_path, $target)) {
                $failed[] = $download->title;
                continue;
            }

            $download->update([
                'file_path' => $target,
                // A file converted where it already was is both the source and the result
                'source_path' => $download->source_path === $download->file_path ? $target : $download->source_path,
            ]);
            $moved++;
        }

        return ['moved' => $moved, 'failed' => $failed];
    }

    /**
     * Same folder, ignoring trailing and mixed slashes, and letter case on Windows
     */
    public static function samePath(string $a, string $b): bool
    {
        $normalize = fn (string $path) => rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($normalize($a), $normalize($b)) === 0
            : $normalize($a) === $normalize($b);
    }

    /**
     * "Song.mp3", or "Song (1).mp3", "Song (2).mp3", ... when that name is taken in $folder
     */
    private static function freeName(string $folder, string $file): string
    {
        $name = pathinfo($file, PATHINFO_FILENAME);
        $extension = pathinfo($file, PATHINFO_EXTENSION);

        for ($counter = 0; ; $counter++) {
            $candidate = $folder . DIRECTORY_SEPARATOR . $name . ($counter ? " ({$counter})" : '') . ($extension !== '' ? '.' . $extension : '');

            if (!file_exists($candidate)) {
                return $candidate;
            }
        }
    }
}
