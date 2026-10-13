<?php

namespace App\Services;

use App\Support\ProcessOutput;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class MetadataService
{
    /**
     * Tag audio file with metadata.
     *
     * With $clearEmpty, empty values remove the existing tag (used when editing tags);
     * otherwise they are left out.
     */
    public function tagFile(string $filePath, array $metadata, bool $clearEmpty = false): bool
    {
        try {
            if (!file_exists($filePath)) {
                throw new Exception("File does not exist: {$filePath}");
            }

            $tagMap = [
                'title' => 'title',
                'artist' => 'artist',
                'album' => 'album',
                'year' => 'date',
                'genre' => 'genre',
            ];

            $metadataArgs = [];

            foreach ($tagMap as $key => $tag) {
                $value = trim((string) ($metadata[$key] ?? ''));

                if ($value !== '' || ($clearEmpty && array_key_exists($key, $metadata))) {
                    // "tag=" (empty) makes ffmpeg drop the tag
                    $metadataArgs[] = '-metadata';
                    $metadataArgs[] = "{$tag}={$value}";
                }
            }

            if (empty($metadataArgs)) {
                return true; // No metadata to add
            }

            $tempPath = $this->tempPathFor($filePath);

            // -map 0 keeps every stream, including embedded cover art
            $command = ['ffmpeg', '-y', '-i', $filePath, '-map', '0', ...$metadataArgs, '-codec', 'copy'];
            if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'mp3') {
                $command = [...$command, '-id3v2_version', '3'];
            }

            $result = Process::timeout(300)->run([...$command, $tempPath]);

            if (!$result->successful()) {
                throw new Exception('Metadata tagging failed: ' . ProcessOutput::error($result));
            }

            if (!file_exists($tempPath)) {
                throw new Exception('Temp file was not created during metadata tagging');
            }

            // Replace original with tagged file
            unlink($filePath);
            rename($tempPath, $filePath);

            return true;
        } catch (Exception $e) {
            Log::error('Metadata tagging failed', [
                'file' => $filePath,
                'metadata' => $metadata,
                'error' => $e->getMessage(),
            ]);

            // Clean up temp file if it exists
            if (isset($tempPath) && file_exists($tempPath)) {
                @unlink($tempPath);
            }

            throw $e;
        }
    }

    /**
     * Extract metadata from YouTube video info
     */
    public function extractMetadataFromYouTube(array $videoInfo): array
    {
        return [
            'title' => $videoInfo['title'] ?? 'Unknown Title',
            'artist' => $videoInfo['artist'] ?? $videoInfo['uploader'] ?? 'Unknown Artist',
            'album' => $videoInfo['album'] ?? null,
            'year' => isset($videoInfo['upload_date']) ? substr($videoInfo['upload_date'], 0, 4) : null,
            'genre' => $videoInfo['genre'] ?? 'Music',
        ];
    }

    /**
     * Attach a thumbnail to the audio file as embedded cover art
     */
    public function attachThumbnail(string $audioPath, string $thumbnailPath): bool
    {
        try {
            if (!file_exists($audioPath)) {
                throw new Exception("Audio file does not exist: {$audioPath}");
            }

            if (!file_exists($thumbnailPath)) {
                throw new Exception("Thumbnail file does not exist: {$thumbnailPath}");
            }

            $tempPath = $this->tempPathFor($audioPath);

            // YouTube thumbnails are often WebP, which MP3/FLAC cover art does not support,
            // so the image is always re-encoded to JPEG instead of stream-copied.
            $command = [
                'ffmpeg', '-y',
                '-i', $audioPath,
                '-i', $thumbnailPath,
                '-map', '0:a', '-map', '1:v',
                '-c:a', 'copy',
                '-c:v', 'mjpeg',
                '-disposition:v', 'attached_pic',
                '-metadata:s:v', 'title=Album cover',
                '-metadata:s:v', 'comment=Cover (front)',
            ];

            if (strtolower(pathinfo($audioPath, PATHINFO_EXTENSION)) === 'mp3') {
                $command = [...$command, '-id3v2_version', '3'];
            }

            $command[] = $tempPath;

            $result = Process::timeout(300)->run($command);

            if (!$result->successful()) {
                // Thumbnail attachment is optional, log but don't fail
                Log::warning('Thumbnail attachment failed', [
                    'audio' => $audioPath,
                    'thumbnail' => $thumbnailPath,
                    'error' => ProcessOutput::error($result),
                ]);

                if (file_exists($tempPath)) {
                    @unlink($tempPath);
                }

                return false;
            }

            if (file_exists($tempPath)) {
                unlink($audioPath);
                rename($tempPath, $audioPath);
                return true;
            }

            return false;
        } catch (Exception $e) {
            Log::warning('Thumbnail attachment failed', [
                'audio' => $audioPath,
                'thumbnail' => $thumbnailPath,
                'error' => $e->getMessage(),
            ]);

            // Clean up temp file if it exists
            if (isset($tempPath) && file_exists($tempPath)) {
                @unlink($tempPath);
            }

            return false;
        }
    }

    /**
     * Download thumbnail from URL
     */
    public function downloadThumbnail(string $url, string $savePath): bool
    {
        try {
            $response = Http::timeout(30)->get($url);

            if (!$response->successful() || $response->body() === '') {
                throw new Exception('Failed to download thumbnail (HTTP ' . $response->status() . ')');
            }

            file_put_contents($savePath, $response->body());

            return file_exists($savePath);
        } catch (Exception $e) {
            Log::warning('Thumbnail download failed', [
                'url' => $url,
                'save_path' => $savePath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Sibling temp file used while ffmpeg rewrites a file in place
     */
    private function tempPathFor(string $filePath): string
    {
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);

        return pathinfo($filePath, PATHINFO_DIRNAME) . '/' . pathinfo($filePath, PATHINFO_FILENAME) . '_temp.' . $ext;
    }
}
