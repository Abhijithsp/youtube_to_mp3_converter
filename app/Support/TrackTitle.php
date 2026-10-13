<?php

namespace App\Support;

class TrackTitle
{
    /**
     * Words that mark a bracketed or trailing part of a YouTube title as noise,
     * e.g. "(Official Music Video)", "[HD]", "| Lyrics", "(Visualizer)".
     */
    private const NOISE = 'official(?:\s+(?:music|lyrics?|audio|hd|4k|video|visuali[sz]er|clip|mv))*(?:\s+(?:video|audio|clip))?'
        . '|(?:music|lyrics?|audio|hd|4k)\s+video|lyrics?|audio|video|visuali[sz]er|explicit|clean|clip officiel'
        . '|hd|hq|4k|8k|uhd|1080p|720p|480p|m\/?v|free download|out now';

    /**
     * Remove noise such as "(Official Music Video)" while keeping meaningful parts
     * like "(Extended Mix)", "(feat. X)" or "(Live)".
     */
    public static function clean(?string $title): string
    {
        $title = trim((string) $title);
        if ($title === '') {
            return '';
        }

        $noise = '(?:' . self::NOISE . ')';
        $cleaned = $title;

        // Bracketed noise: (Official Video), [HD], 【MV】, {Lyrics}
        $cleaned = preg_replace('/\s*[\(\[\{【]\s*' . $noise . '(?:\s*[\/&,+]\s*' . $noise . ')*\s*[\)\]\}】]/iu', '', $cleaned);

        // Trailing noise after a separator: "Song | Official Video", "Song - Lyrics"
        $cleaned = preg_replace('/\s*(?:\||｜|\/\/|-|–|—)\s*' . $noise . '\s*$/iu', '', $cleaned);

        // Hashtags at the end: "Song #shorts #music"
        $cleaned = preg_replace('/(?:\s+#[\p{L}\p{N}_]+)+\s*$/u', '', $cleaned);

        $cleaned = trim(preg_replace('/\s+/u', ' ', $cleaned), " \t-–—|");

        // Never clean a title away completely
        return $cleaned !== '' ? $cleaned : $title;
    }

    /**
     * Split "Artist - Song" into [artist, song], or null when the title has no such form.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function split(?string $title): ?array
    {
        if (!preg_match('/^(.+?)\s+[-–—]\s+(.+)$/u', trim((string) $title), $m)) {
            return null;
        }

        $artist = trim($m[1]);
        $song = trim($m[2]);

        // "Artist - Song" artists are short; long left parts are usually not an artist
        if ($artist === '' || $song === '' || mb_strlen($artist) > 60) {
            return null;
        }

        return [$artist, $song];
    }

    /**
     * Title and artist to use for a video.
     *  - YouTube Music releases carry real artist/track fields: "Artist - Track"
     *  - otherwise the (cleaned) video title, with the artist taken from an
     *    "Artist - Song" title, falling back to the channel name
     *
     * @return array{title: string, artist: string|null}
     */
    public static function suggest(array $info, bool $clean = true): array
    {
        if (!empty($info['has_music_metadata']) && !empty($info['track'])) {
            return [
                'title' => trim($info['artist'] . ' - ' . $info['track']),
                'artist' => $info['artist'],
            ];
        }

        $title = $clean ? self::clean($info['title'] ?? '') : trim((string) ($info['title'] ?? ''));
        $split = self::split($title);

        return [
            'title' => $title !== '' ? $title : 'Unknown Title',
            'artist' => $split[0] ?? ($info['uploader'] ?? $info['artist'] ?? null),
        ];
    }

    /**
     * Title for the audio tag: "D-Sturb - LADY" with artist "D-Sturb" is tagged as "LADY",
     * so music players show "LADY" by "D-Sturb" instead of repeating the artist.
     */
    public static function songTitle(?string $title, ?string $artist): string
    {
        $title = trim((string) $title);
        $split = self::split($title);

        if ($split && $artist && mb_strtolower($split[0]) === mb_strtolower(trim($artist))) {
            return $split[1];
        }

        return $title;
    }
}
