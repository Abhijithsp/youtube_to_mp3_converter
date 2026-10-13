<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\ProcessOutput;
use Exception;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

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
            $result = $this->runYtDlp(['--dump-json', '--no-playlist', '--no-warnings', $url]);

            if (!$result->successful()) {
                throw $this->failure('Failed to extract video info', $result);
            }

            $info = json_decode($result->output(), true);

            if (!$info) {
                throw new Exception('Invalid JSON response from yt-dlp');
            }

            return [
                'id' => $info['id'] ?? null,
                'title' => $info['title'] ?? 'Unknown Title',
                'artist' => $info['artist'] ?? $info['uploader'] ?? null,
                'album' => $info['album'] ?? null,
                'thumbnail' => $info['thumbnail'] ?? null,
                'duration' => isset($info['duration']) ? (int) round($info['duration']) : null,
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
