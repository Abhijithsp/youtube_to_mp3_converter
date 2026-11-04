<?php

namespace App\Support;

class FileName
{
    /**
     * Names Windows refuses as file names, with or without an extension
     */
    private const RESERVED = '/^(CON|PRN|AUX|NUL|COM[0-9]|LPT[0-9])$/i';

    /**
     * Keep the title as it is ("D-Sturb - LADY (Official Music Video)"), only
     * removing what Windows, macOS or Linux do not allow in a file name.
     * Non-Latin titles are kept too (PHP uses UTF-8 file names on Windows).
     */
    public static function fromTitle(?string $title, string $fallback = 'download'): string
    {
        $name = (string) $title;

        // < > : " / \ | ? * and control characters are invalid on Windows
        $name = preg_replace('~[<>:"/\\\\|?*\x00-\x1F\x7F]+~u', ' ', $name) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';

        // Windows strips trailing dots and spaces, which would break later lookups
        $name = rtrim(trim($name), '. ');

        // Stay well below the 255-byte limit (room for " (12).flac" and temp suffixes)
        $name = rtrim(mb_strcut($name, 0, 180, 'UTF-8'), '. ');

        if ($name === '' || preg_match(self::RESERVED, $name)) {
            return $fallback;
        }

        return $name;
    }

    /**
     * Claim "<name>.<ext>" in $folder, or "<name> (1).<ext>", "<name> (2).<ext>", ... when taken,
     * by creating an empty file there. Creating it ('x' mode fails when the file exists) means
     * two jobs running at the same time can never pick the same name.
     */
    public static function reserve(string $folder, string $name, string $extension): string
    {
        if (!is_dir($folder)) {
            throw new \RuntimeException("Folder does not exist: {$folder}");
        }

        for ($counter = 0; ; $counter++) {
            $path = $folder . '/' . $name . ($counter ? " ({$counter})" : '') . '.' . $extension;

            if (file_exists($path)) {
                continue;
            }

            if ($handle = @fopen($path, 'x')) {
                fclose($handle);

                return $path;
            }

            // Not taken but still not creatable: the folder is read-only
            if (!file_exists($path)) {
                throw new \RuntimeException("Cannot create {$path}");
            }
        }
    }
}
