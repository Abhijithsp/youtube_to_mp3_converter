<?php

namespace App\Services;

use App\Support\ProcessOutput;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Builds the small loudness outline shown in the player's seek bar.
 */
class WaveformService
{
    /**
     * Number of bars stored; the player draws fewer on narrow screens.
     */
    public const POINTS = 160;

    /**
     * Decode at a very low sample rate: enough for a loudness outline, and it keeps
     * the data small (about 4 KB per second of audio, ~14 MB for an hour).
     */
    private const SAMPLE_RATE = 2000;

    /**
     * Loudness peaks between 0 and 100, or null when the file cannot be decoded.
     *
     * @return list<int>|null
     */
    public function generate(string $path, int $points = self::POINTS): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        try {
            // Mono, 16-bit little-endian raw PCM on stdout (array command: no shell quoting)
            $result = Process::timeout(180)->run([
                'ffmpeg', '-v', 'error', '-i', $path,
                '-map', '0:a:0', '-ac', '1', '-ar', (string) self::SAMPLE_RATE,
                '-f', 's16le', '-',
            ]);

            if (!$result->successful()) {
                throw new Exception(ProcessOutput::error($result));
            }

            return $this->peaks($result->output(), $points);
        } catch (Exception $e) {
            Log::warning('Waveform generation failed', ['file' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Turn raw 16-bit PCM into $points loudness values.
     *
     * Uses RMS per bar instead of the peak sample: loud, heavily mastered music hits
     * the maximum almost everywhere, which would draw a flat wall.
     *
     * @return list<int>|null
     */
    public function peaks(string $pcm, int $points = self::POINTS): ?array
    {
        $samples = intdiv(strlen($pcm), 2);
        if ($samples < $points) {
            return null;
        }

        $perBar = $samples / $points;
        $levels = [];

        for ($i = 0; $i < $points; $i++) {
            $start = (int) floor($i * $perBar);
            $end = (int) floor(($i + 1) * $perBar);

            // Unpack one bar at a time to keep memory low for long files
            $chunk = unpack('v*', substr($pcm, $start * 2, ($end - $start) * 2)) ?: [];
            $sum = 0.0;
            foreach ($chunk as $value) {
                $signed = $value >= 0x8000 ? $value - 0x10000 : $value;
                $sum += $signed * $signed;
            }

            $levels[] = $chunk ? sqrt($sum / count($chunk)) : 0.0;
        }

        $max = max($levels);
        if ($max <= 0) {
            return array_fill(0, $points, 0);
        }

        // Normalise to the loudest bar; the curve lifts quiet parts so they stay visible
        return array_map(
            fn (float $level) => (int) round(100 * (($level / $max) ** 0.7)),
            $levels
        );
    }
}
