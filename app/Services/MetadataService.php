<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class MetadataService
{
    /**
     * Tag audio file with metadata
     */
    public function tagFile(string $filePath, array $metadata): bool
    {
        try {
            if (!file_exists($filePath)) {
                throw new Exception("File does not exist: {$filePath}");
            }

            $commands = [];

            if (!empty($metadata['title'])) {
                $commands[] = sprintf('-metadata title="%s"', escapeshellarg($metadata['title']));
            }

            if (!empty($metadata['artist'])) {
                $commands[] = sprintf('-metadata artist="%s"', escapeshellarg($metadata['artist']));
            }

            if (!empty($metadata['album'])) {
                $commands[] = sprintf('-metadata album="%s"', escapeshellarg($metadata['album']));
            }

            if (!empty($metadata['year'])) {
                $commands[] = sprintf('-metadata date="%s"', escapeshellarg($metadata['year']));
            }

            if (!empty($metadata['genre'])) {
                $commands[] = sprintf('-metadata genre="%s"', escapeshellarg($metadata['genre']));
            }

            if (empty($commands)) {
                return true; // No metadata to add
            }

            $ext = pathinfo($filePath, PATHINFO_EXTENSION);
            $tempPath = pathinfo($filePath, PATHINFO_DIRNAME) . '/' . pathinfo($filePath, PATHINFO_FILENAME) . '_temp.' . $ext;

            $command = sprintf(
                'ffmpeg -i "%s" %s -codec copy "%s" -y',
                escapeshellarg($filePath),
                implode(' ', $commands),
                escapeshellarg($tempPath)
            );

            $result = Process::timeout(300)->run($command);

            if (!$result->successful()) {
                throw new Exception('Metadata tagging failed: ' . $result->errorOutput());
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
     * Download and attach thumbnail to audio file
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

            $ext = pathinfo($audioPath, PATHINFO_EXTENSION);
            $tempPath = pathinfo($audioPath, PATHINFO_DIRNAME) . '/' . pathinfo($audioPath, PATHINFO_FILENAME) . '_temp.' . $ext;

            $command = sprintf(
                'ffmpeg -i "%s" -i "%s" -map 0:0 -map 1:0 -c copy -id3v2_version 3 -metadata:s:v title="Album cover" -metadata:s:v comment="Cover (front)" "%s" -y',
                escapeshellarg($audioPath),
                escapeshellarg($thumbnailPath),
                escapeshellarg($tempPath)
            );

            $result = Process::timeout(300)->run($command);

            if (!$result->successful()) {
                // Thumbnail attachment is optional, log but don't fail
                Log::warning('Thumbnail attachment failed', [
                    'audio' => $audioPath,
                    'thumbnail' => $thumbnailPath,
                    'error' => $result->errorOutput(),
                ]);
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
            $imageData = file_get_contents($url);
            
            if ($imageData === false) {
                throw new Exception('Failed to download thumbnail');
            }

            file_put_contents($savePath, $imageData);

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
}
