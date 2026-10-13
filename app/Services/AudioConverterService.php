<?php

namespace App\Services;

use App\Support\ProcessOutput;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class AudioConverterService
{
    /**
     * Matches ffmpeg progress lines, e.g. "size=    1280kB time=00:00:10.00"
     * (older builds) or "size=      72KiB time=00:00:03.00" (ffmpeg 6+).
     */
    public const PROGRESS_PATTERN = '/size=\s*(\d+)\s*(?:kB|KiB)\s+time=(\d+:\d+:\d+(?:\.\d+)?)/';

    /**
     * Longest audio (seconds) for which trailing silence is also removed; that filter
     * reverses the audio in memory, which is too heavy for hour-long mixes.
     */
    private const TRAILING_SILENCE_MAX_SECONDS = 1200;

    /**
     * Build the ffmpeg command for a conversion.
     *
     * Options: trim_start / trim_end (seconds), remove_silence (bool),
     * duration (seconds, used to decide on trailing-silence removal).
     */
    public function buildCommand(string $inputPath, string $outputPath, string $format = 'mp3', int $quality = 320, array $options = []): array
    {
        $bitrate = [192 => '192k', 256 => '256k', 320 => '320k'][$quality] ?? '320k';

        $command = ['ffmpeg', '-y'];

        // Input seeking: fast, and exact because the audio is re-encoded
        $start = (int) ($options['trim_start'] ?? 0);
        $end = isset($options['trim_end']) ? (int) $options['trim_end'] : null;
        if ($start > 0) {
            $command = [...$command, '-ss', (string) $start];
        }
        if ($end !== null && $end > $start) {
            $command = [...$command, '-to', (string) $end];
        }

        // Commands are passed as arrays so arguments are never re-parsed by a shell:
        // paths/titles with spaces stay intact and no PATH lookup through cmd.exe is needed.
        $command = [...$command, '-i', $inputPath, '-vn', '-ac', '2'];

        if (!empty($options['remove_silence'])) {
            $silence = 'silenceremove=start_periods=1:start_threshold=-50dB:start_silence=0.2';
            $duration = (int) ($options['duration'] ?? 0);
            $filter = $duration > 0 && $duration <= self::TRAILING_SILENCE_MAX_SECONDS
                ? "{$silence},areverse,{$silence},areverse"
                : $silence;
            $command = [...$command, '-af', $filter];
        }

        $command = [...$command, ...match ($format) {
            'flac' => ['-ar', '44100', '-c:a', 'flac', '-compression_level', '8'],
            // Studio-quality WAV: 24-bit PCM at 48 kHz, YouTube's native sample rate (no resampling)
            'wav' => ['-ar', '48000', '-c:a', 'pcm_s24le'],
            default => ['-ar', '44100', '-c:a', 'libmp3lame', '-b:a', $bitrate],
        }];

        $command[] = $outputPath;

        return $command;
    }

    /**
     * Convert audio file to specified format and quality
     */
    public function convert(string $inputPath, string $outputPath, string $format = 'mp3', int $quality = 320, ?callable $progressCallback = null, array $options = []): bool
    {
        try {
            if (!file_exists($inputPath)) {
                throw new Exception("Input file does not exist: {$inputPath}");
            }

            $command = $this->buildCommand($inputPath, $outputPath, $format, $quality, $options);

            if ($progressCallback) {
                $result = Process::timeout(600)->run($command, function (string $type, string $output) use ($progressCallback) {
                    if ($progress = $this->parseProgress($output)) {
                        $progressCallback(...$progress);
                    }
                });
            } else {
                $result = Process::timeout(600)->run($command);
            }

            if (!$result->successful()) {
                throw new Exception('Conversion failed: ' . ProcessOutput::error($result));
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
     * Read the latest [sizeKb, "HH:MM:SS.ss"] pair from an ffmpeg output chunk
     */
    public function parseProgress(string $output): ?array
    {
        if (!preg_match_all(self::PROGRESS_PATTERN, $output, $matches)) {
            return null;
        }

        return [(int) end($matches[1]), end($matches[2])];
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

            $result = Process::run([
                'ffprobe', '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $filePath,
            ]);

            if (!$result->successful()) {
                throw new Exception('Failed to get audio info: ' . ProcessOutput::error($result));
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
        return $this->getFfmpegVersion() !== null;
    }

    /**
     * Get FFmpeg version
     */
    public function getFfmpegVersion(): ?string
    {
        try {
            $result = Process::run(['ffmpeg', '-version']);
        } catch (Exception $e) {
            // Thrown when the executable cannot be started at all
            return null;
        }

        if (!$result->successful()) {
            return null;
        }

        preg_match('/ffmpeg version ([^\s]+)/', $result->output(), $matches);

        return $matches[1] ?? null;
    }

    /**
     * Extract audio from video file
     */
    public function extractAudio(string $videoPath, string $audioPath): bool
    {
        try {
            $result = Process::timeout(600)->run([
                'ffmpeg', '-y', '-i', $videoPath, '-vn', '-acodec', 'copy', $audioPath,
            ]);

            if (!$result->successful()) {
                throw new Exception('Audio extraction failed: ' . ProcessOutput::error($result));
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
