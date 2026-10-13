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
}
