<?php

namespace App\Services;

use App\Models\Download;
use App\Support\FileName;
use App\Support\ProcessOutput;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Cuts a finished download into one file per video chapter (mixes, full albums,
 * DJ sets). The audio is copied, not re-encoded, so there is no quality loss.
 *
 * The tracks go into a folder named after the video: "Video title/01 - First.mp3".
 * The original download becomes track 1 and one more download is added per chapter.
 */
class ChapterSplitService
{
    public function __construct(
        private WaveformService $waveformService
    ) {}

    /**
     * @param  array  $chapters  [['title', 'start', 'end'], ...] in seconds
     * @return Download[] the tracks, in order (the first is $download itself)
     */
    public function split(Download $download, string $sourceFile, array $chapters, string $downloadPath): array
    {
        $count = count($chapters);
        $albumName = $download->album ?: $download->title;
        $folder = $this->uniqueFolder(rtrim($downloadPath, '/\\') . DIRECTORY_SEPARATOR . FileName::fromTitle($download->title, 'Chapters'));

        if (!mkdir($folder, 0755, true) && !is_dir($folder)) {
            throw new Exception("Could not create the folder {$folder}");
        }

        $written = [];
        try {
            foreach (array_values($chapters) as $i => $chapter) {
                $number = $i + 1;
                $title = $chapter['title'];
                $file = $folder . DIRECTORY_SEPARATOR
                    . sprintf('%02d - %s.%s', $number, FileName::fromTitle($title, 'Track ' . $number), $download->format);

                $this->cut($sourceFile, $file, (float) $chapter['start'], (float) $chapter['end'], [
                    'title' => $title,
                    'artist' => $download->artist,
                    'album' => $albumName,
                    'date' => $download->year,
                    'genre' => $download->genre,
                    'track' => "{$number}/{$count}",
                ]);

                $written[] = ['file' => $file, 'title' => $title, 'number' => $number,
                    'duration' => (int) round($chapter['end'] - $chapter['start'])];
            }
        } catch (Exception $e) {
            foreach ($written as $track) {
                @unlink($track['file']);
            }
            @rmdir($folder);

            throw $e;
        }

        // The whole recording is no longer needed once every chapter has its own file
        @unlink($sourceFile);

        $tracks = [];
        foreach ($written as $track) {
            $attributes = [
                'title' => $track['title'],
                'album' => $albumName,
                'chapter_index' => $track['number'],
                'file_path' => $track['file'],
                'file_size' => filesize($track['file']),
                'duration' => $track['duration'],
                'waveform' => $this->waveformService->generate($track['file']),
                'lyrics_status' => 'none', // chapters are rarely songs LRCLIB knows
            ];

            if ($track['number'] === 1) {
                $download->update($attributes);
                $tracks[] = $download;
                continue;
            }

            $tracks[] = Download::create($attributes + $download->only([
                'youtube_url', 'youtube_id', 'artist', 'year', 'genre', 'thumbnail_url', 'format', 'quality',
            ]) + [
                'normalize_volume' => (bool) $download->normalize_volume,
                'remove_silence' => (bool) $download->remove_silence,
                'split_chapters' => true,
                'status' => 'completed',
                'status_text' => 'Completed',
                'progress' => 100,
                'conversion_progress' => 100,
                'completed_at' => now(),
            ]);
        }

        return $tracks;
    }

    private function cut(string $source, string $target, float $start, float $end, array $tags): void
    {
        $command = ['ffmpeg', '-y', '-ss', (string) $start, '-to', (string) $end, '-i', $source, '-map', '0', '-c', 'copy'];

        foreach ($tags as $tag => $value) {
            if ($value !== null && $value !== '') {
                $command = [...$command, '-metadata', "{$tag}={$value}"];
            }
        }

        if (str_ends_with(strtolower($target), '.mp3')) {
            $command = [...$command, '-id3v2_version', '3'];
        }

        $result = Process::timeout(300)->run([...$command, $target]);

        if (!$result->successful() || !is_file($target)) {
            Log::error('Cutting a chapter failed', ['target' => $target, 'error' => ProcessOutput::error($result)]);

            throw new Exception('Could not cut chapter "' . $tags['title'] . '": ' . ProcessOutput::error($result));
        }
    }

    /**
     * "Mix", then "Mix (1)" when that folder exists already
     */
    private function uniqueFolder(string $folder): string
    {
        $candidate = $folder;
        for ($i = 1; file_exists($candidate); $i++) {
            $candidate = "{$folder} ({$i})";
        }

        return $candidate;
    }
}
