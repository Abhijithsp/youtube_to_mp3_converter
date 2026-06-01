<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class AudioConverterService
{
    /**
     * Convert audio file to specified format and quality
     */
    public function convert(string $inputPath, string $outputPath, string $format = 'mp3', int $quality = 320, ?callable $progressCallback = null): bool
    {
        try {
            if (!file_exists($inputPath)) {
                throw new Exception("Input file does not exist: {$inputPath}");
            }

            $bitrateMap = [
                192 => '192k',
                256 => '256k',
                320 => '320k',
            ];

            $bitrate = $bitrateMap[$quality] ?? '320k';

            $codecMap = [
                'mp3' => 'libmp3lame',
                'flac' => 'flac',
            ];

            $codec = $codecMap[$format] ?? 'libmp3lame';

            $command = sprintf(
                'ffmpeg -i "%s" -vn -ar 44100 -ac 2 -b:a %s -c:a %s "%s" -y',
                escapeshellarg($inputPath),
                $bitrate,
                $codec,
                escapeshellarg($outputPath)
            );

            if ($progressCallback) {
                $result = Process::timeout(600)->run($command, function (string $type, string $output) use ($progressCallback) {
                    // Match size=  1280kB time=00:00:10.00
                    if (preg_match_all('/size=\s*(\d+)kB\s+time=([\d:]+\.\d+)/', $output, $matches)) {
                        $lastSizeKb = end($matches[1]);
                        $lastTime = end($matches[2]);
                        $progressCallback((int) $lastSizeKb, $lastTime);
                    }
                });
            } else {
                $result = Process::timeout(600)->run($command);
            }

            if (!$result->successful()) {
                throw new Exception('Conversion failed: ' . $result->errorOutput());
            }

            if (!file_exists($outputPath)) {
                throw new Exception('Output file was not created');
            }

            return true;
        } catch (Exception $e) {
            Log::error('Audio conversion failed', [
                'input' => $inputPath,
                'output' => $outputPath,
                'format' => $format,
                'quality' => $quality,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get audio file information
     */
    public function getAudioInfo(string $filePath): array
    {
        try {
            if (!file_exists($filePath)) {
                throw new Exception("File does not exist: {$filePath}");
            }

            $command = sprintf(
                'ffprobe -v quiet -print_format json -show_format -show_streams "%s"',
                escapeshellarg($filePath)
            );

            $result = Process::run($command);

            if (!$result->successful()) {
                throw new Exception('Failed to get audio info: ' . $result->errorOutput());
            }

            $info = json_decode($result->output(), true);

            $audioStream = collect($info['streams'] ?? [])
                ->firstWhere('codec_type', 'audio');

            return [
                'duration' => (int) ($info['format']['duration'] ?? 0),
                'bitrate' => (int) ($info['format']['bit_rate'] ?? 0),
                'size' => (int) ($info['format']['size'] ?? 0),
                'codec' => $audioStream['codec_name'] ?? 'unknown',
                'sample_rate' => (int) ($audioStream['sample_rate'] ?? 0),
                'channels' => (int) ($audioStream['channels'] ?? 0),
            ];
        } catch (Exception $e) {
            Log::error('Failed to get audio info', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Check if FFmpeg is installed
     */
    public function validateFfmpegInstalled(): bool
    {
        $result = Process::run('ffmpeg -version');
        return $result->successful();
    }

    /**
     * Get FFmpeg version
     */
    public function getFfmpegVersion(): ?string
    {
        $result = Process::run('ffmpeg -version');
        
        if (!$result->successful()) {
            return null;
        }

        $output = $result->output();
        preg_match('/ffmpeg version ([^\s]+)/', $output, $matches);
        
        return $matches[1] ?? null;
    }

    /**
     * Extract audio from video file
     */
    public function extractAudio(string $videoPath, string $audioPath): bool
    {
        try {
            $command = sprintf(
                'ffmpeg -i "%s" -vn -acodec copy "%s" -y',
                escapeshellarg($videoPath),
                escapeshellarg($audioPath)
            );

            $result = Process::timeout(600)->run($command);

            if (!$result->successful()) {
                throw new Exception('Audio extraction failed: ' . $result->errorOutput());
            }

            return file_exists($audioPath);
        } catch (Exception $e) {
            Log::error('Audio extraction failed', [
                'video' => $videoPath,
                'audio' => $audioPath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
