<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class YouTubeService
{
    /**
     * Validate if the URL is a valid YouTube URL
     */
    public function validateUrl(string $url): bool
    {
        $pattern = '/^(https?:\/\/)?(www\.)?(youtube\.com|youtu\.be|music\.youtube\.com)\/.+$/';
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
        ]);
    }

    /**
     * Extract video information using yt-dlp
     */
    public function extractVideoInfo(string $url): array
    {
        try {
            $command = [
                'yt-dlp',
                '--dump-json',
                '--no-playlist',
                $url
            ];

            $result = Process::env($this->getProcessEnv())->run($command);

            if (!$result->successful()) {
                throw new Exception('Failed to extract video info: ' . $result->errorOutput());
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
                'duration' => $info['duration'] ?? null,
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
            $command = [
                'yt-dlp',
                '--dump-json',
                '--flat-playlist',
                $url
            ];

            $result = Process::env($this->getProcessEnv())->run($command);

            if (!$result->successful()) {
                throw new Exception('Failed to extract playlist info: ' . $result->errorOutput());
            }

            $lines = explode("\n", trim($result->output()));
            $videos = [];

            foreach ($lines as $index => $line) {
                if (empty($line)) {
                    continue;
                }

                $info = json_decode($line, true);
                if (!$info) {
                    continue;
                }

                $videos[] = [
                    'id' => $info['id'] ?? null,
                    'title' => $info['title'] ?? 'Unknown Title',
                    'url' => $info['url'] ?? "https://www.youtube.com/watch?v={$info['id']}",
                    'duration' => $info['duration'] ?? null,
                    'position' => $index + 1,
                ];
            }

            // Get playlist title
            $playlistCommand = [
                'yt-dlp',
                '--dump-json',
                '--playlist-items', '0',
                $url
            ];

            $playlistResult = Process::env($this->getProcessEnv())->run($playlistCommand);
            $playlistInfo = json_decode($playlistResult->output(), true);

            return [
                'id' => $this->extractPlaylistId($url),
                'title' => $playlistInfo['playlist_title'] ?? 'Unknown Playlist',
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
            // Build command as array for better handling
            $command = [
                'yt-dlp',
                '-f', 'bestaudio', // Download best audio format only
                '-o', $outputPath,
                '--no-playlist',
                '--newline', // Force newline output for easier parsing
                '--no-warnings',
                $url
            ];

            Log::info('Executing yt-dlp command', ['command' => implode(' ', $command)]);

            if ($progressCallback) {
                $result = Process::env($this->getProcessEnv())->timeout(600)->run($command, function (string $type, string $output) use ($progressCallback) {
                    if (preg_match_all('/\[download\]\s+([\d\.]+)%\s+of\s+~?\s*([\d\.]+[KMG]iB|[\d\.]+B)/', $output, $matches)) {
                        $lastProgress = end($matches[1]);
                        $lastSize = end($matches[2]);
                        $progressCallback((float) $lastProgress, $lastSize);
                    } elseif (preg_match_all('/\[download\]\s+([\d\.]+)%/', $output, $matches)) {
                        $lastProgress = end($matches[1]);
                        $progressCallback((float) $lastProgress, null);
                    }
                });
            } else {
                $result = Process::env($this->getProcessEnv())->timeout(600)->run($command);
            }

            if (!$result->successful()) {
                Log::error('yt-dlp command failed', [
                    'exit_code' => $result->exitCode(),
                    'output' => $result->output(),
                    'error' => $result->errorOutput(),
                ]);
                throw new Exception('Download failed: ' . $result->errorOutput());
            }

            Log::info('yt-dlp download completed', ['output' => $result->output()]);

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
     * Check if yt-dlp is installed
     */
    public function checkYtDlpInstalled(): bool
    {
        $result = Process::env($this->getProcessEnv())->run(['yt-dlp', '--version']);
        return $result->successful();
    }

    /**
     * Get yt-dlp version
     */
    public function getYtDlpVersion(): ?string
    {
        $result = Process::env($this->getProcessEnv())->run(['yt-dlp', '--version']);
        return $result->successful() ? trim($result->output()) : null;
    }
}

