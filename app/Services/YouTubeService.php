<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\ProcessOutput;
use Exception;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

class YouTubeService
{
    /**
     * Browsers yt-dlp can read cookies from (--cookies-from-browser)
     */
    public const COOKIE_BROWSERS = ['firefox', 'chrome', 'chromium', 'edge', 'brave', 'opera', 'vivaldi', 'whale', 'safari'];

    /**
     * Network resilience options added to every yt-dlp call
     */
    private const NETWORK_OPTIONS = [
        '--retries', '10',
        '--fragment-retries', '10',
        '--extractor-retries', '3',
        '--socket-timeout', '30',
    ];

    /**
     * Validate if the URL is a valid YouTube URL
     */
    public function validateUrl(string $url): bool
    {
        $pattern = '/^(https?:\/\/)?(www\.|m\.)?(youtube\.com|youtu\.be|music\.youtube\.com)\/.+$/';
        return preg_match($pattern, $url) === 1;
    }

    /**
     * Extract video ID from YouTube URL
     */
    public function extractVideoId(string $url): ?string
    {
        $pattern = '/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?|shorts)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/';
        preg_match($pattern, $url, $matches);
        return $matches[1] ?? null;
    }

    /**
     * Extract playlist ID from YouTube URL
     */
    public function extractPlaylistId(string $url): ?string
    {
        $pattern = '/[?&]list=([^&]+)/';
        preg_match($pattern, $url, $matches);
        return $matches[1] ?? null;
    }

    /**
     * Get process environment variables
     */
    private function getProcessEnv(): array
    {
        $tempPath = storage_path('app/temp');
        if (!file_exists($tempPath)) {
            mkdir($tempPath, 0755, true);
        }

        // merge with system environment to ensure SYSTEMROOT and headers are present
        return array_merge(getenv(), [
            'TEMP' => $tempPath,
            'TMP' => $tempPath,
            'TMPDIR' => $tempPath,
            // Make yt-dlp (Python) print UTF-8 instead of the Windows console code page
            'PYTHONUTF8' => '1',
            'PYTHONIOENCODING' => 'utf-8',
        ]);
    }

    /**
     * yt-dlp arguments that pass YouTube cookies, from the Settings page or .env.
     * Signed-in cookies avoid YouTube's "Sign in to confirm you're not a bot" check.
     */
    public function cookieOptions(): array
    {
        $file = trim((string) Setting::get('ytdlp_cookies_file', config('youtube-converter.cookies_file')));

        if ($file !== '' && is_file($file)) {
            return ['--cookies', $file];
        }

        $browser = trim((string) Setting::get('ytdlp_cookies_browser', config('youtube-converter.cookies_from_browser')));

        return $browser !== '' ? ['--cookies-from-browser', $browser] : [];
    }

    /**
     * Run yt-dlp with the shared options. When cookies cannot be loaded (browser open
     * and locking its database, unsupported encryption, profile not found...), the
     * command is retried without cookies instead of failing the download.
     */
    private function runYtDlp(array $arguments, int $timeout = 120, ?callable $output = null): ProcessResult
    {
        $cookieOptions = $this->cookieOptions();

        $result = $this->runCommand([...$cookieOptions, ...$arguments], $timeout, $output);

        if ($cookieOptions && !$result->successful() && $this->isCookieLoadError($result)) {
            Log::warning('yt-dlp could not load cookies, retrying without them', [
                'cookies' => $cookieOptions,
                'error' => ProcessOutput::error($result),
            ]);

            $result = $this->runCommand($arguments, $timeout, $output);
        }

        return $result;
    }

    private function runCommand(array $arguments, int $timeout, ?callable $output): ProcessResult
    {
        return Process::env($this->getProcessEnv())
            ->timeout($timeout)
            ->run(['yt-dlp', ...self::NETWORK_OPTIONS, ...$arguments], $output);
    }

    private function isCookieLoadError(ProcessResult $result): bool
    {
        return (bool) preg_match(
            '/cookie(s)? database|extracting cookies|failed to decrypt|DPAPI|keyring|could not find .* cookies|unsupported browser|cookies file/i',
            $result->errorOutput()
        );
    }

    /**
     * Turn a failed yt-dlp run into an exception with an actionable message
     */
    private function failure(string $prefix, ProcessResult $result): Exception
    {
        $error = ProcessOutput::error($result);

        if (preg_match('/confirm you.?re not a bot|Sign in to confirm|HTTP Error 429/i', $error)) {
            $error .= "\nYouTube is rate-limiting this computer. Wait a few minutes, or set a browser (or cookies.txt file) under Settings → YouTube access.";
        } elseif (preg_match('/HTTP Error 403|Requested format is not available|nsig extraction failed|Unable to extract/i', $error)) {
            $error .= "\nThis is usually fixed by updating yt-dlp (`yt-dlp -U`, or `winget upgrade yt-dlp` / `pip install -U yt-dlp`).";
        } elseif (preg_match('/timed out/i', $error)) {
            $error .= "\nA network request or a yt-dlp helper/plugin timed out. Downloads are retried automatically.";
        }

        return new Exception("{$prefix}: {$error}");
    }

    /**
     * Extract video information using yt-dlp
     */
    public function extractVideoInfo(string $url): array
    {
        try {
            $info = $this->readVideoInfo($url);

            return [
                'id' => $info['id'] ?? null,
                'title' => $info['title'] ?? 'Unknown Title',
                // "artist"/"track" only exist for YouTube Music releases
                'artist' => $info['artist'] ?? $info['uploader'] ?? null,
                'track' => $info['track'] ?? null,
                'has_music_metadata' => isset($info['artist'], $info['track']),
                'uploader' => $info['uploader'] ?? $info['channel'] ?? null,
                'album' => $info['album'] ?? null,
                'year' => $this->yearFrom($info),
                'genre' => $info['genre'] ?? null,
                'thumbnail' => $info['thumbnail'] ?? null,
                'duration' => isset($info['duration']) ? (int) round($info['duration']) : null,
                'chapters' => $this->chaptersFrom($info),
                'video_qualities' => $this->videoQualitiesFrom($info),
                'url' => $url,
            ];
        } catch (Exception $e) {
            Log::error('YouTube video info extraction failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function readVideoInfo(string $url, int $timeout = 120): array
    {
        $result = $this->runYtDlp(['--dump-json', '--no-playlist', '--no-warnings', $url], $timeout);

        if (!$result->successful()) {
            throw $this->failure('Failed to extract video info', $result);
        }

        $info = json_decode($result->output(), true);
        if (!is_array($info) || !$info) {
            throw new Exception('Invalid JSON response from yt-dlp');
        }

        return $info;
    }

    public function extractDirectMp4(string $url, int $maxHeight): array
    {
        $info = $this->readVideoInfo($url, 25);
        $streams = collect($info['formats'] ?? [])
            ->filter(fn (array $stream) => empty($stream['has_drm'])
                && ($stream['protocol'] ?? null) === 'https'
                && empty($stream['fragments'])
                && str_starts_with($stream['url'] ?? '', 'https://'));

        $videos = $streams
            ->filter(fn (array $stream) => in_array($stream['ext'] ?? null, ['mp4', 'webm'], true)
                && !empty($stream['vcodec']) && $stream['vcodec'] !== 'none'
                && ($stream['height'] ?? 0) > 0 && $stream['height'] <= $maxHeight)
            ->sortByDesc(fn (array $stream) => [$stream['height'], $stream['ext'] === 'mp4', $stream['tbr'] ?? 0]);
        $combined = $videos->first(fn (array $stream) => !empty($stream['acodec']) && $stream['acodec'] !== 'none');
        $video = $videos->first(fn (array $stream) => ($stream['acodec'] ?? null) === 'none');
        $audio = $streams
            ->filter(fn (array $stream) => in_array($stream['ext'] ?? null, ['m4a', 'webm'], true)
                && ($stream['vcodec'] ?? null) === 'none'
                && !empty($stream['acodec']) && $stream['acodec'] !== 'none')
            ->sortByDesc(fn (array $stream) => [$stream['ext'] === 'm4a', $stream['abr'] ?? $stream['tbr'] ?? 0])
            ->first();

        $split = $video && $audio && $video['height'] > ($combined['height'] ?? 0);
        $format = $split ? $video : $combined;

        return [
            'title' => $info['title'] ?? 'Unknown Title',
            'height' => isset($format['height']) ? (int) $format['height'] : null,
            'direct_url' => $split ? null : ($combined['url'] ?? null),
            'video_url' => $split ? $video['url'] : null,
            'audio_url' => $split ? $audio['url'] : null,
        ];
    }

    /**
     * Chapters of a video (mixes, albums, sets): [['title', 'start', 'end'], ...] in seconds.
     * Fewer than two chapters is no split, so an empty list is returned.
     */
    private function chaptersFrom(array $info): array
    {
        $chapters = [];
        foreach ($info['chapters'] ?? [] as $i => $chapter) {
            $start = (float) ($chapter['start_time'] ?? 0);
            $end = (float) ($chapter['end_time'] ?? 0);
            if ($end - $start < 1) {
                continue;
            }

            $title = trim((string) ($chapter['title'] ?? ''));
            $chapters[] = [
                'title' => $title !== '' ? $title : 'Part ' . ($i + 1),
                'start' => round($start, 2),
                'end' => round($end, 2),
            ];
        }

        return count($chapters) >= 2 ? $chapters : [];
    }

    /**
     * Resolutions this video really has, best first, with the stream each one would use
     * (the same preference as videoFormatSelector) and its size including the audio
     */
    private function videoQualitiesFrom(array $info): array
    {
        $formats = collect($info['formats'] ?? []);
        $sizeOf = fn (array $format) => (int) ($format['filesize'] ?? $format['filesize_approx'] ?? 0);

        $audioSize = $formats
            ->filter(fn ($format) => ($format['vcodec'] ?? null) === 'none' && ($format['ext'] ?? null) === 'm4a')
            ->map($sizeOf)
            ->max() ?? 0;

        return $formats
            // Video-only streams, as videoFormatSelector picks them
            ->filter(fn ($format) => !in_array($format['vcodec'] ?? 'none', ['none', null], true)
                && in_array($format['acodec'] ?? 'none', ['none', null], true)
                && ($format['height'] ?? 0) >= 100)
            ->groupBy('height')
            ->map(function ($streams, $height) use ($sizeOf, $audioSize) {
                // Streams of a known size first, so the list can show one
                $streams = $streams->sortByDesc(fn ($format) => [$format['fps'] ?? 0, $sizeOf($format) > 0, $format['tbr'] ?? 0]);
                $stream = $streams->first(fn ($format) => str_starts_with($format['vcodec'], 'avc1'))
                    ?? $streams->first(fn ($format) => str_starts_with($format['vcodec'], 'av01'))
                    ?? $streams->first();

                // Portrait videos (Shorts): 1080x1920 is a 1080p video
                $lines = min((int) $height, (int) ($stream['width'] ?? $height));
                $fps = (int) round($stream['fps'] ?? 0);
                $size = $sizeOf($stream);

                return [
                    'height' => (int) $height,
                    'label' => $lines . 'p' . ($fps > 30 ? $fps : '') . match (true) {
                        $lines >= 4320 => ' (8K)',
                        $lines >= 2160 => ' (4K)',
                        $lines >= 1440 => ' (2K)',
                        default => '',
                    },
                    'codec' => match (true) {
                        str_starts_with($stream['vcodec'], 'avc1') => 'H.264',
                        str_starts_with($stream['vcodec'], 'av01') => 'AV1',
                        str_starts_with($stream['vcodec'], 'vp') => 'VP9',
                        default => $stream['vcodec'],
                    },
                    // H.264 plays on practically everything; the newer codecs do not
                    'compatible' => str_starts_with($stream['vcodec'], 'avc1'),
                    'size' => $size ? $size + $audioSize : null,
                ];
            })
            ->sortByDesc('height')
            ->values()
            ->all();
    }

    private function yearFrom(array $info): ?int
    {
        foreach ([$info['release_year'] ?? null, $info['release_date'] ?? null, $info['upload_date'] ?? null] as $value) {
            if ($value && preg_match('/^(\d{4})/', (string) $value, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * Search YouTube videos by text ("ytsearchN:" in yt-dlp, no API key needed)
     */
    public function search(string $query, int $limit = 12): array
    {
        $limit = max(1, min(30, $limit));

        $result = $this->runYtDlp(['-J', '--flat-playlist', '--no-warnings', "ytsearch{$limit}:{$query}"], 60);

        if (!$result->successful()) {
            throw $this->failure('Search failed', $result);
        }

        $data = json_decode($result->output(), true);

        return collect($data['entries'] ?? [])
            // Skip channels, playlists and live streams; only regular videos can be converted
            ->filter(fn ($entry) => !empty($entry['id']) && strlen($entry['id']) === 11
                && !in_array($entry['live_status'] ?? null, ['is_live', 'is_upcoming'], true))
            ->map(fn ($entry) => [
                'id' => $entry['id'],
                'title' => $entry['title'] ?? 'Untitled',
                'url' => "https://www.youtube.com/watch?v={$entry['id']}",
                'channel' => $entry['channel'] ?? $entry['uploader'] ?? null,
                'duration' => isset($entry['duration']) ? (int) round($entry['duration']) : null,
                'views' => $entry['view_count'] ?? null,
                'thumbnail' => "https://i.ytimg.com/vi/{$entry['id']}/mqdefault.jpg",
            ])
            ->values()
            ->all();
    }

    /**
     * Extract playlist information using yt-dlp
     */
    public function extractPlaylistInfo(string $url): array
    {
        try {
            // -J prints a single JSON document holding the playlist title and its flat entries
            $result = $this->runYtDlp(['-J', '--flat-playlist', '--no-warnings', $url], 300);

            if (!$result->successful()) {
                throw $this->failure('Failed to extract playlist info', $result);
            }

            $playlistInfo = json_decode($result->output(), true);

            if (!is_array($playlistInfo)) {
                throw new Exception('Invalid JSON response from yt-dlp');
            }

            $videos = [];

            foreach (array_values($playlistInfo['entries'] ?? []) as $index => $info) {
                if (empty($info['id'])) {
                    continue;
                }

                $videos[] = [
                    'id' => $info['id'],
                    'title' => $info['title'] ?? 'Unknown Title',
                    'url' => $info['url'] ?? "https://www.youtube.com/watch?v={$info['id']}",
                    'duration' => isset($info['duration']) ? (int) round($info['duration']) : null,
                    'position' => $index + 1,
                ];
            }

            return [
                'id' => $this->extractPlaylistId($url),
                'title' => $playlistInfo['title'] ?? 'Unknown Playlist',
                'videos' => $videos,
                'total' => count($videos),
            ];
        } catch (Exception $e) {
            Log::error('YouTube playlist info extraction failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Download audio from YouTube video
     */
    public function downloadAudio(string $url, string $outputPath, string $format = 'mp3', int $quality = 320, ?callable $progressCallback = null): bool
    {
        try {
            $arguments = [
                // Best audio-only stream; fall back to the best combined stream when a
                // video has no separate audio (ffmpeg strips the video afterwards)
                '-f', 'bestaudio/best',
                '-o', $outputPath,
                '--no-playlist',
                '--no-part',
                '--newline', // Force newline output for easier parsing
                '--no-warnings',
                $url,
            ];

            Log::info('Executing yt-dlp download', ['url' => $url, 'output' => $outputPath]);

            $result = $this->runYtDlp($arguments, 600, $progressCallback
                ? function (string $type, string $output) use ($progressCallback) {
                    if ($progress = $this->parseDownloadProgress($output)) {
                        $progressCallback(...$progress);
                    }
                }
                : null);

            if (!$result->successful()) {
                Log::error('yt-dlp command failed', [
                    'exit_code' => $result->exitCode(),
                    'error' => ProcessOutput::error($result),
                ]);
                throw $this->failure('Download failed', $result);
            }

            return true;
        } catch (Exception $e) {
            Log::error('YouTube audio download failed', [
                'url' => $url,
                'output_path' => $outputPath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * yt-dlp format choice for a resolution: H.264 with AAC plays everywhere, so it comes
     * first. Above 1080p YouTube has no H.264, so AV1 is next, then whatever exists at
     * that height. The last steps only apply when the height is no longer offered.
     */
    public function videoFormatSelector(int $height): string
    {
        return implode('/', [
            "bv[height={$height}][vcodec^=avc1]+ba[ext=m4a]",
            "bv[height={$height}][vcodec^=av01]+ba[ext=m4a]",
            "bv[height={$height}]+ba[ext=m4a]",
            "bv[height<={$height}]+ba",
            "b[height<={$height}]",
            'b',
        ]);
    }

    /**
     * Download a video as "<outputPath>.mp4". The streams are joined as they are, without
     * re-encoding, so nothing is lost. The callback gets the overall percent and size.
     */
    public function downloadVideo(string $url, string $outputPath, int $height, int $timeout, ?callable $progressCallback = null): bool
    {
        $arguments = [
            '-f', $this->videoFormatSelector($height),
            '--merge-output-format', 'mp4',
            '--remux-video', 'mp4', // A single stream that is not MP4 yet
            // A literal % in the path would be read as a template field
            '-o', str_replace('%', '%%', $outputPath) . '.%(ext)s',
            '--no-playlist',
            '--no-part',
            '--newline',
            '--no-warnings',
            $url,
        ];

        Log::info('Executing yt-dlp video download', ['url' => $url, 'output' => $outputPath, 'height' => $height]);

        $streams = 0;

        $result = $this->runYtDlp($arguments, $timeout, $progressCallback
            ? function (string $type, string $output) use ($progressCallback, &$streams) {
                if ($progress = $this->parseVideoProgress($output, $streams)) {
                    $progressCallback(...$progress);
                }
            }
            : null);

        if (!$result->successful()) {
            Log::error('yt-dlp video download failed', [
                'exit_code' => $result->exitCode(),
                'error' => ProcessOutput::error($result),
            ]);
            throw $this->failure('Download failed', $result);
        }

        return true;
    }

    /**
     * Overall [percent, size|null] of a video download. HD video comes as two downloads, each
     * counting from 0 to 100. The video is nearly all of the size, so it fills 90% of the bar
     * and the audio the rest. $streams carries the count of started downloads between chunks.
     */
    public function parseVideoProgress(string $output, int &$streams): ?array
    {
        $streams += preg_match_all('/\[download\] Destination:/', $output);

        if (!$progress = $this->parseDownloadProgress($output)) {
            return null;
        }

        return [$streams > 1 ? 90 + $progress[0] * 0.1 : $progress[0] * 0.9, $progress[1]];
    }

    /**
     * Read the latest [percent, size|null] pair from a yt-dlp output chunk,
     * e.g. "[download]  42.0% of ~  3.10MiB at 1.2MiB/s ETA 00:02"
     */
    public function parseDownloadProgress(string $output): ?array
    {
        if (preg_match_all('/\[download\]\s+([\d\.]+)%\s+of\s+~?\s*([\d\.]+[KMG]iB|[\d\.]+B)/', $output, $matches)) {
            return [(float) end($matches[1]), end($matches[2])];
        }

        if (preg_match_all('/\[download\]\s+([\d\.]+)%/', $output, $matches)) {
            return [(float) end($matches[1]), null];
        }

        return null;
    }

    /**
     * Check that YouTube can be reached with the current cookie settings
     * (one metadata request for a short, long-lived public video).
     *
     * @return array{ok: bool, message: string, cookies: string|null}
     */
    public function testAccess(): array
    {
        $cookieOptions = $this->cookieOptions();
        $cookies = $cookieOptions ? implode(' ', $cookieOptions) : null;

        try {
            $result = $this->runCommand([...$cookieOptions, '--simulate', '--no-warnings', '--print', 'id', 'https://www.youtube.com/watch?v=jNQXAC9IVRw'], 120, null);
        } catch (Exception $e) {
            return ['ok' => false, 'message' => 'yt-dlp could not be started: ' . $e->getMessage(), 'cookies' => $cookies];
        }

        if ($result->successful()) {
            return ['ok' => true, 'message' => $cookies ? 'YouTube is reachable using your cookies.' : 'YouTube is reachable.', 'cookies' => $cookies];
        }

        if ($cookieOptions && $this->isCookieLoadError($result)) {
            return [
                'ok' => false,
                'message' => 'Could not read cookies: ' . ProcessOutput::error($result, 2)
                    . "\nClose the browser and try again, use Firefox, or export a cookies.txt file. Downloads will continue without cookies.",
                'cookies' => $cookies,
            ];
        }

        return ['ok' => false, 'message' => $this->failure('YouTube check failed', $result)->getMessage(), 'cookies' => $cookies];
    }

    /**
     * Newest yt-dlp release on GitHub (cached; null when offline)
     */
    public function latestYtDlpVersion(): ?string
    {
        return Cache::remember('yt-dlp-latest-version', now()->addHours(6), function () {
            try {
                $response = Http::timeout(8)
                    ->withHeaders(['Accept' => 'application/vnd.github+json'])
                    ->get('https://api.github.com/repos/yt-dlp/yt-dlp/releases/latest');

                return $response->successful() ? ltrim((string) $response->json('tag_name'), 'v') ?: null : null;
            } catch (Exception $e) {
                return null;
            }
        });
    }

    /**
     * Update yt-dlp: its own updater first (official binaries), then winget when it was
     * installed that way. pip/other package managers only get the command to run.
     *
     * @return array{ok: bool, updated: bool, version: string|null, message: string, output: string}
     */
    public function updateYtDlp(): array
    {
        $before = $this->getYtDlpVersion();

        if ($before === null) {
            return ['ok' => false, 'updated' => false, 'version' => null, 'message' => 'yt-dlp is not installed or not on PATH.', 'output' => ''];
        }

        $result = Process::env($this->getProcessEnv())->timeout(180)->run(['yt-dlp', '-U']);
        $output = ProcessOutput::error($result, 8);
        $combined = trim($result->output() . "\n" . $result->errorOutput());

        // Self-update is refused for pip/package-manager installs
        $managed = preg_match('/package manager|with pip|PyPi|Use that to update/i', $combined);
        $pip = preg_match('/with pip|PyPi/i', $combined);

        if ($managed && !$pip && $this->installedWithWinget()) {
            try {
                $winget = Process::timeout(300)->run([
                    'winget', 'upgrade', '--id', 'yt-dlp.yt-dlp', '--exact', '--silent',
                    '--accept-source-agreements', '--accept-package-agreements', '--disable-interactivity',
                ]);
                $output = ProcessOutput::error($winget, 8);
                $managed = false;
                $result = $winget;
            } catch (Exception $e) {
                $output = $e->getMessage();
            }
        }

        $after = $this->getYtDlpVersion();
        $updated = $after !== null && $after !== $before;

        if ($updated) {
            Cache::forget('yt-dlp-latest-version');

            return ['ok' => true, 'updated' => true, 'version' => $after, 'message' => "Updated yt-dlp from {$before} to {$after}.", 'output' => $output];
        }

        if ($managed) {
            $hint = preg_match('/pip|PyPi/i', $combined) ? 'python -m pip install -U yt-dlp' : 'your package manager (e.g. winget upgrade yt-dlp, brew upgrade yt-dlp)';

            return ['ok' => false, 'updated' => false, 'version' => $before, 'message' => "yt-dlp was installed with a package manager. Update it with {$hint}.", 'output' => $output];
        }

        if ($result->successful() || preg_match('/up to date|No update/i', $combined)) {
            return ['ok' => true, 'updated' => false, 'version' => $before, 'message' => "yt-dlp {$before} is already the latest version.", 'output' => $output];
        }

        return ['ok' => false, 'updated' => false, 'version' => $before, 'message' => 'The update failed: ' . $output, 'output' => $output];
    }

    private function installedWithWinget(): bool
    {
        $path = (new ExecutableFinder())->find('yt-dlp');

        return $path !== null && str_contains(strtolower($path), 'winget');
    }

    /**
     * yt-dlp needs a JavaScript runtime (Deno by default) to solve YouTube's
     * player challenges; without one some videos fail or lose formats.
     */
    public function getDenoVersion(): ?string
    {
        try {
            $result = Process::timeout(20)->run(['deno', '--version']);
        } catch (Exception $e) {
            return null;
        }

        return $result->successful() && preg_match('/deno\s+([\d.]+)/i', $result->output(), $m) ? $m[1] : null;
    }

    /**
     * Check if yt-dlp is installed
     */
    public function checkYtDlpInstalled(): bool
    {
        return $this->getYtDlpVersion() !== null;
    }

    /**
     * Get yt-dlp version
     */
    public function getYtDlpVersion(): ?string
    {
        try {
            $result = Process::env($this->getProcessEnv())->run(['yt-dlp', '--version']);
        } catch (Exception $e) {
            // Thrown when the executable cannot be started at all
            return null;
        }

        return $result->successful() ? trim($result->output()) : null;
    }
}
