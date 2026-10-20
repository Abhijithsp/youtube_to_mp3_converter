<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\ProcessOutput;
use App\Support\TrackTitle;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Reads video and audio files the user wants converted: whether they really hold
 * audio, how long they are, their tags, and a cover picture.
 */
class LocalFileService
{
    /**
     * Extensions offered in the file pickers. Files are checked by their contents
     * (ffprobe), so this only keeps unrelated files out of the lists.
     */
    public const EXTENSIONS = [
        // Video
        'mp4', 'm4v', 'mkv', 'mov', 'avi', 'webm', 'wmv', 'flv', 'mpg', 'mpeg', 'ts', 'mts', 'm2ts', '3gp', 'ogv',
        // Audio
        'mp3', 'wav', 'flac', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wma', 'aiff', 'aif', 'alac', 'ape', 'amr', 'mka', 'weba', 'ac3', 'dts',
    ];

    public const VIDEO_EXTENSIONS = ['mp4', 'm4v', 'mkv', 'mov', 'avi', 'webm', 'wmv', 'flv', 'mpg', 'mpeg', 'ts', 'mts', 'm2ts', '3gp', 'ogv'];

    public static function isMediaName(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * What the file holds, and suggested tags. 'error' is set when it cannot be converted.
     *
     * @return array{name: string, size: int, duration: int, has_video: bool, has_cover: bool,
     *     codec: ?string, bitrate: ?int, audio_tracks: int, title: string, artist: ?string,
     *     album: ?string, year: ?int, genre: ?string, error: ?string}
     */
    public function inspect(string $path, ?string $name = null): array
    {
        $name ??= basename($path);
        $info = [
            'name' => $name,
            'size' => is_file($path) ? (int) filesize($path) : 0,
            'duration' => 0,
            'has_video' => false,
            'has_cover' => false,
            'codec' => null,
            'bitrate' => null,
            'audio_tracks' => 0,
            'error' => null,
        ];

        if (!is_file($path)) {
            return ['error' => 'The file is no longer there.'] + $info + $this->suggestTags($name, []);
        }

        $probe = $this->probe($path);

        if ($probe === null) {
            return ['error' => 'This file could not be read. It may be damaged or not a video or audio file.'] + $info + $this->suggestTags($name, []);
        }

        $streams = $probe['streams'] ?? [];
        $audio = array_values(array_filter($streams, fn ($s) => ($s['codec_type'] ?? null) === 'audio'));
        $pictures = array_filter($streams, fn ($s) => ($s['codec_type'] ?? null) === 'video' && !empty($s['disposition']['attached_pic']));
        $videos = array_filter($streams, fn ($s) => ($s['codec_type'] ?? null) === 'video' && empty($s['disposition']['attached_pic']));

        // Tags can sit on the container or on the audio stream (Ogg/Opus), in any letter case
        $tags = array_change_key_case(($audio[0]['tags'] ?? []) + ($probe['format']['tags'] ?? []), CASE_LOWER);

        $first = $audio[0] ?? [];
        // The audio's own bitrate; for audio-only files the container's is the same thing.
        // (A video's container bitrate includes the picture, so it is not shown.)
        $bitrate = (int) ($first['bit_rate'] ?? 0) ?: (!$videos ? (int) ($probe['format']['bit_rate'] ?? 0) : 0);

        $info = [
            'duration' => (int) round((float) ($probe['format']['duration'] ?? $first['duration'] ?? 0)),
            'has_video' => (bool) $videos,
            // A video frame works as cover art too
            'has_cover' => (bool) $pictures || (bool) $videos,
            'codec' => $first['codec_name'] ?? null,
            'bitrate' => $bitrate > 0 ? (int) round($bitrate / 1000) : null,
            'audio_tracks' => count($audio),
        ] + $info;

        if (!$audio) {
            $info['error'] = $videos ? 'This video has no sound to convert.' : 'This file has no audio.';
        }

        return $info + $this->suggestTags($name, $tags);
    }

    /**
     * Title and artist from the file's tags, or else from its name:
     * "Arijit Singh - Tum Hi Ho (Official Video).mp4" → "Arijit Singh - Tum Hi Ho" by "Arijit Singh"
     */
    public function suggestTags(string $name, array $tags): array
    {
        $tagTitle = trim((string) ($tags['title'] ?? ''));
        $tagArtist = trim((string) ($tags['artist'] ?? $tags['album_artist'] ?? '')) ?: null;
        $year = preg_match('/\b(19|20)\d{2}\b/', (string) ($tags['date'] ?? $tags['year'] ?? ''), $m) ? (int) $m[0] : null;

        $common = [
            'album' => trim((string) ($tags['album'] ?? '')) ?: null,
            'year' => $year,
            'genre' => trim((string) ($tags['genre'] ?? '')) ?: null,
        ];

        if ($tagTitle !== '') {
            $title = Setting::cleanTitles() ? TrackTitle::clean($tagTitle) : $tagTitle;

            // Same form as YouTube downloads: the file is named "Artist - Song"
            if ($tagArtist && !TrackTitle::split($title)) {
                $title = "{$tagArtist} - {$title}";
            }

            return ['title' => $title, 'artist' => $tagArtist ?? TrackTitle::split($title)[0] ?? null] + $common;
        }

        $base = trim(pathinfo($name, PATHINFO_FILENAME));
        // "my_song_name" → "my song name"
        if (!str_contains($base, ' ')) {
            $base = trim(str_replace('_', ' ', $base));
        }

        $suggested = TrackTitle::suggest(['title' => $base !== '' ? $base : 'Untitled'], Setting::cleanTitles());

        return ['title' => $suggested['title'], 'artist' => $tagArtist ?? $suggested['artist']] + $common;
    }

    /**
     * Save a cover picture as JPEG: the embedded artwork, or else a frame from the
     * video about a tenth of the way in (past black intros and title cards)
     */
    public function extractCover(string $path, string $target, int $duration = 0): bool
    {
        $probe = $this->probe($path);
        if ($probe === null) {
            return false;
        }

        $pictureIndex = null;
        $videoIndex = null;
        foreach ($probe['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? null) !== 'video') {
                continue;
            }
            if (!empty($stream['disposition']['attached_pic'])) {
                $pictureIndex ??= $stream['index'];
            } else {
                $videoIndex ??= $stream['index'];
            }
        }

        if ($pictureIndex === null && $videoIndex === null) {
            return false;
        }

        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        $command = ['ffmpeg', '-y', '-v', 'error'];
        if ($pictureIndex === null && $duration > 10) {
            $command = [...$command, '-ss', (string) round($duration / 10)];
        }

        $command = [
            ...$command,
            '-i', $path,
            '-map', '0:' . ($pictureIndex ?? $videoIndex),
            '-frames:v', '1',
            // Cover size like YouTube thumbnails, keeping the shape
            '-vf', 'scale=min(640\,iw):-2',
            '-q:v', '3',
            $target,
        ];

        try {
            $result = Process::timeout(120)->run($command);
        } catch (Throwable $e) {
            return false;
        }

        if (!$result->successful() || !is_file($target) || filesize($target) === 0) {
            Log::warning('Cover extraction failed', ['file' => $path, 'error' => ProcessOutput::error($result)]);
            @unlink($target);

            return false;
        }

        return true;
    }

    /**
     * ffprobe's description of the file, or null when it cannot be read
     */
    private function probe(string $path): ?array
    {
        try {
            $result = Process::timeout(60)->run([
                'ffprobe', '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $path,
            ]);
        } catch (Throwable $e) {
            return null;
        }

        if (!$result->successful()) {
            return null;
        }

        $data = json_decode($result->output(), true);

        return is_array($data) && isset($data['format']) ? $data : null;
    }
}
