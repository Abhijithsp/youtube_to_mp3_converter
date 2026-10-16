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
     * otherwise they are left out. $extraTags adds more metadata keys => tag names.
     */
    public function tagFile(string $filePath, array $metadata, bool $clearEmpty = false, array $extraTags = []): bool
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
            ] + $extraTags;

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
     * Save lyrics into the file, where music players show them: an ID3 USLT frame
     * for MP3 (ffmpeg would write a custom TXXX frame that players ignore) and a
     * LYRICS Vorbis comment for FLAC. WAV has no lyrics tag. Returns false on failure.
     */
    public function writeLyrics(string $filePath, string $lyrics): bool
    {
        $lyrics = trim($lyrics);
        if ($lyrics === '' || !is_file($filePath)) {
            return false;
        }

        try {
            return match (strtolower(pathinfo($filePath, PATHINFO_EXTENSION))) {
                'mp3' => $this->writeId3Lyrics($filePath, $lyrics),
                'flac' => $this->tagFile($filePath, ['lyrics' => $lyrics], extraTags: ['lyrics' => 'LYRICS']),
                default => false,
            };
        } catch (Exception $e) {
            Log::warning('Writing lyrics failed', ['file' => $filePath, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Add (or replace) the USLT frame in an MP3's ID3v2.3 tag, as written by ffmpeg
     */
    private function writeId3Lyrics(string $filePath, string $lyrics): bool
    {
        $data = file_get_contents($filePath);
        if ($data === false || strlen($data) < 10 || !str_starts_with($data, 'ID3') || ord($data[3]) !== 3) {
            return false;
        }

        // Unsynchronised or extended-header tags are not written by ffmpeg; leave them alone
        if (ord($data[5]) & 0xC0) {
            return false;
        }

        $tagSize = $this->syncsafeDecode(substr($data, 6, 4));
        $body = substr($data, 10, $tagSize);
        $audio = substr($data, 10 + $tagSize);

        // Keep every frame except an old USLT; stop at the padding
        $frames = '';
        for ($pos = 0; $pos + 10 <= strlen($body);) {
            $id = substr($body, $pos, 4);
            if (!preg_match('/^[A-Z0-9]{4}$/', $id)) {
                break;
            }
            $size = unpack('N', substr($body, $pos + 4, 4))[1];
            if ($size < 0 || $pos + 10 + $size > strlen($body)) {
                return false;
            }
            if ($id !== 'USLT') {
                $frames .= substr($body, $pos, 10 + $size);
            }
            $pos += 10 + $size;
        }

        // UTF-16 with BOM (encoding 1) so every script survives; empty description
        $utf16 = fn (string $text) => "\xFF\xFE" . mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $content = "\x01" . 'eng' . $utf16('') . "\x00\x00" . $utf16(str_replace("\r\n", "\n", $lyrics));
        $frames .= 'USLT' . pack('N', strlen($content)) . "\x00\x00" . $content;

        $header = 'ID3' . "\x03\x00" . "\x00" . $this->syncsafeEncode(strlen($frames));
        $tempPath = $this->tempPathFor($filePath);

        if (file_put_contents($tempPath, $header . $frames . $audio) === false) {
            @unlink($tempPath);

            return false;
        }

        // A file that is playing can be locked (Windows): keep the original then
        if (!@unlink($filePath)) {
            @unlink($tempPath);

            return false;
        }

        return rename($tempPath, $filePath);
    }

    private function syncsafeDecode(string $bytes): int
    {
        $size = 0;
        foreach (str_split($bytes) as $byte) {
            $size = ($size << 7) | (ord($byte) & 0x7F);
        }

        return $size;
    }

    private function syncsafeEncode(int $size): string
    {
        return chr(($size >> 21) & 0x7F) . chr(($size >> 14) & 0x7F) . chr(($size >> 7) & 0x7F) . chr($size & 0x7F);
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
