<?php

namespace App\Http\Controllers;

use App\Models\Download;
use App\Jobs\DownloadYoutubeAudioJob;
use App\Services\MetadataService;
use App\Services\WaveformService;
use App\Services\AudioConverterService;
use App\Services\LyricsService;
use App\Support\ProcessOutput;
use Illuminate\Support\Facades\Process;
use App\Services\YouTubeService;
use App\Models\Setting;
use App\Support\FileExplorer;
use App\Support\LocalFileAccess;
use App\Support\FileName;
use App\Support\TrackTitle;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadController extends Controller
{
    /**
     * Most links accepted by one bulk request
     */
    private const BULK_LIMIT = 50;

    /**
     * Most songs in one ZIP download
     */
    private const ZIP_LIMIT = 500;

    /**
     * Ringtone length in seconds (iPhone allows up to 40)
     */
    private const RINGTONE_MIN = 3;

    private const RINGTONE_MAX = 40;

    public function __construct(
        private YouTubeService $youtubeService
    ) {}

    /**
     * Display a listing of downloads
     */
    public function index(Request $request): JsonResponse
    {
        $query = Download::query()->latest();

        // Filter by status
        // Filter by status; accepts a comma separated list, e.g. status=pending,processing
        if ($request->filled('status')) {
            $query->whereIn('status', explode(',', (string) $request->status));
        }

        // Specific downloads, e.g. ids=4,5,6 (the Files tab follows its conversions)
        if ($request->filled('ids')) {
            $query->whereIn('id', array_map('intval', explode(',', (string) $request->ids)));
        }

        // Search by title or artist
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('artist', 'like', "%{$search}%");
            });
        }

        $downloads = $query->paginate(min(max((int) $request->get('per_page', 20), 1), 100));

        return response()->json($downloads);
    }

    /**
     * Store a newly created download
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'youtube_url' => 'required|url',
            'format' => ['required', Rule::in(Download::FORMATS)],
            'quality' => 'required|in:192,256,320',
            'title' => 'nullable|string|max:255',
            'artist' => 'nullable|string|max:255',
            'album' => 'nullable|string|max:255',
            'year' => 'nullable|integer|min:1900|max:2100',
            'genre' => 'nullable|string|max:100',
            // Optional cut, in seconds
            'trim_start' => 'nullable|integer|min:0',
            'trim_end' => 'nullable|integer|min:1',
            'remove_silence' => 'nullable|boolean',
            'normalize_volume' => 'nullable|boolean',
            'split_chapters' => 'nullable|boolean',
        ]);

        $validator->after(function ($validator) use ($request) {
            if ($request->filled('trim_end') && (int) $request->trim_end <= (int) $request->trim_start) {
                $validator->errors()->add('trim_end', 'The end time must be after the start time.');
            }
            if ($request->boolean('split_chapters') && ((int) $request->trim_start > 0 || $request->filled('trim_end'))) {
                $validator->errors()->add('split_chapters', 'Splitting into chapters works on the whole video. Remove the trim first.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Validate YouTube URL
        if (!$this->youtubeService->validateUrl($request->youtube_url)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid YouTube URL'
            ], 422);
        }

        try {
            // Extract video ID
            $videoId = $this->youtubeService->extractVideoId($request->youtube_url);

            if (!$videoId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Could not find a video ID in this URL. Use the playlist importer for playlist links.'
                ], 422);
            }

            $edited = $request->filled('trim_start') || $request->filled('trim_end') || $request->boolean('remove_silence')
                || $request->boolean('normalize_volume') || $request->boolean('split_chapters');

            // Edited (trimmed) downloads are always made fresh
            $existing = $edited ? null : $this->findExisting($videoId, $request->format, (string) $request->quality);

            if ($existing) {
                return response()->json([
                    'success' => true,
                    'message' => 'This video has already been downloaded',
                    'download' => $existing
                ]);
            }

            // Create download record
            $download = Download::create([
                'youtube_url' => $request->youtube_url,
                'youtube_id' => $videoId,
                'title' => $request->title ?: Download::PLACEHOLDER_TITLE,
                'thumbnail_url' => $request->thumbnail_url,
                'format' => $request->format,
                'quality' => (string) $request->quality,
                'artist' => $request->artist,
                'album' => $request->album,
                'year' => $request->year,
                'genre' => $request->genre,
                'trim_start' => $request->filled('trim_start') && (int) $request->trim_start > 0 ? (int) $request->trim_start : null,
                'trim_end' => $request->filled('trim_end') ? (int) $request->trim_end : null,
                'remove_silence' => $request->boolean('remove_silence'),
                'normalize_volume' => $request->boolean('normalize_volume'),
                'split_chapters' => $request->boolean('split_chapters'),
                'status' => 'pending',
            ]);

            // Dispatch job
            DownloadYoutubeAudioJob::dispatch($download);

            return response()->json([
                'success' => true,
                'message' => 'Download started successfully',
                'download' => $download
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to start download: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Queue several videos at once (links pasted into one box)
     */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'urls' => 'required|array|min:1|max:' . self::BULK_LIMIT,
            'urls.*' => 'required|string|max:2000',
            'format' => ['required', Rule::in(Download::FORMATS)],
            'quality' => 'required|in:192,256,320',
        ], [
            'urls.max' => 'Paste at most ' . self::BULK_LIMIT . ' links at a time.',
        ]);

        $delaySeconds = (int) Setting::get('rate_limit_seconds', config('youtube-converter.rate_limit_seconds', 2));
        $queued = [];
        $skipped = [];
        $seen = [];

        foreach ($data['urls'] as $url) {
            $url = trim($url);

            if (!$this->youtubeService->validateUrl($url)) {
                $skipped[] = ['url' => $url, 'reason' => 'Not a YouTube link'];
                continue;
            }

            $videoId = $this->youtubeService->extractVideoId($url);

            if (!$videoId) {
                $skipped[] = ['url' => $url, 'reason' => $this->youtubeService->extractPlaylistId($url)
                    ? 'Playlist link: import it on the Playlist page'
                    : 'No video found in this link'];
                continue;
            }

            if (isset($seen[$videoId])) {
                $skipped[] = ['url' => $url, 'reason' => 'Listed twice'];
                continue;
            }
            $seen[$videoId] = true;

            if ($existing = $this->findExisting($videoId, $data['format'], (string) $data['quality'])) {
                $skipped[] = ['url' => $url, 'reason' => 'Already downloaded', 'download' => $existing];
                continue;
            }

            $download = Download::create([
                'youtube_url' => $url,
                'youtube_id' => $videoId,
                'title' => Download::PLACEHOLDER_TITLE,
                'format' => $data['format'],
                'quality' => (string) $data['quality'],
                'status' => 'pending',
            ]);

            // Spaced out like playlist imports, to avoid YouTube rate limits
            DownloadYoutubeAudioJob::dispatch($download)->delay(now()->addSeconds(count($queued) * $delaySeconds));

            $queued[] = $download;
        }

        return response()->json([
            'success' => count($queued) > 0,
            'message' => count($queued)
                ? 'Queued ' . count($queued) . ' download' . (count($queued) === 1 ? '' : 's') . '.'
                : 'Nothing was queued.',
            'queued' => $queued,
            'skipped' => $skipped,
        ], count($queued) ? 201 : 422);
    }

    /**
     * Waveform for the player's seek bar. Created on first request for downloads
     * made before waveforms existed, then stored.
     */
    public function waveform(Download $download, WaveformService $waveformService): JsonResponse
    {
        if (!$download->waveform) {
            if ($download->status !== 'completed' || !$download->file_path || !file_exists($download->file_path)) {
                return response()->json(['waveform' => null, 'message' => 'The audio file is not available.'], 404);
            }

            $waveform = $waveformService->generate($download->file_path);

            if (!$waveform) {
                return response()->json(['waveform' => null, 'message' => 'Could not read the audio file.'], 422);
            }

            $download->update(['waveform' => $waveform]);
        }

        return response()->json(['waveform' => $download->waveform]);
    }

    /**
     * Edit the tags of a finished download: re-tags the file and renames it to the new title
     */
    public function update(Request $request, Download $download, MetadataService $metadataService): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'artist' => 'nullable|string|max:255',
            'album' => 'nullable|string|max:255',
            'year' => 'nullable|integer|min:1900|max:2100',
            'genre' => 'nullable|string|max:100',
            'rename' => 'nullable|boolean',
        ]);

        if ($download->status !== 'completed' || !$download->file_path || !file_exists($download->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Only finished downloads whose file still exists can be edited.',
            ], 422);
        }

        $tags = [
            'title' => trim($data['title']),
            'artist' => trim((string) ($data['artist'] ?? '')) ?: null,
            'album' => trim((string) ($data['album'] ?? '')) ?: null,
            'year' => $data['year'] ?? null,
            'genre' => trim((string) ($data['genre'] ?? '')) ?: null,
        ];

        $path = $download->file_path;

        try {
            // Rewrite the tags in the file (cover art is kept); cleared fields remove the tag
            $metadataService->tagFile($path, [
                'title' => TrackTitle::songTitle($tags['title'], $tags['artist']),
                'artist' => $tags['artist'],
                'album' => $tags['album'],
                'year' => $tags['year'],
                'genre' => $tags['genre'],
            ], clearEmpty: true);

            // Rewriting the tags drops the lyrics frame: put the lyrics back
            if ($download->lyrics) {
                $metadataService->writeLyrics($path, $download->lyrics);
            }

            if ($request->boolean('rename', true)) {
                $path = $this->renameToTitle($path, $tags['title'], $download->format);
            }
        } catch (\Throwable $e) {
            $locked = preg_match('/Permission denied|being used by another process|resource busy/i', $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $locked
                    ? 'The file is in use (is it playing or open in another app?). Close it and try again.'
                    : 'Could not update the file: ' . $e->getMessage(),
            ], $locked ? 409 : 500);
        }

        clearstatcache(true, $path);
        $download->update($tags + [
            'file_path' => $path,
            'file_size' => filesize($path),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tags updated.',
            'download' => $download->fresh(),
        ]);
    }

    /**
     * Rename a file to "<title>.<ext>" in the same folder, without overwriting other files
     */
    private function renameToTitle(string $path, string $title, string $extension): string
    {
        $directory = dirname($path);
        $current = basename($path);
        $name = FileName::fromTitle($title, pathinfo($path, PATHINFO_FILENAME));

        // Candidates: "Title.mp3", "Title (1).mp3", ... The first that is free, or that
        // is this very file (e.g. it is already "Title (1).mp3", or only the letter case
        // differs, which Windows treats as the same name), wins.
        for ($counter = 0; ; $counter++) {
            $candidate = $name . ($counter ? " ({$counter})" : '') . '.' . $extension;

            if ($candidate === $current) {
                return $path;
            }

            if (strcasecmp($candidate, $current) === 0 || !file_exists($directory . DIRECTORY_SEPARATOR . $candidate)) {
                break;
            }
        }

        $target = $directory . DIRECTORY_SEPARATOR . $candidate;

        if (!@rename($path, $target)) {
            throw new \RuntimeException('Permission denied while renaming the file');
        }

        return $target;
    }

    /**
     * An earlier, untrimmed download of the same video in the same format/quality
     * whose file still exists
     */
    private function findExisting(string $videoId, string $format, string $quality): ?Download
    {
        return Download::where('youtube_id', $videoId)
            ->where('status', 'completed')
            ->where('format', $format)
            ->where('quality', $quality)
            ->whereNull('trim_start')
            ->whereNull('trim_end')
            ->where('remove_silence', false)
            ->where('normalize_volume', false)
            ->whereNull('chapter_index')
            ->latest()
            ->get()
            ->first(fn (Download $download) => $download->file_path && file_exists($download->file_path));
    }

    /**
     * Search YouTube by text
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => 'required|string|min:2|max:200',
        ]);

        $query = trim($data['q']);

        try {
            // Each search is a YouTube request: reuse recent answers to avoid rate limits
            $results = Cache::remember('yt-search:' . sha1(mb_strtolower($query)), now()->addMinutes(30), fn () => $this->youtubeService->search($query));

            return response()->json(['results' => $results]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage(), 'results' => []], 502);
        }
    }

    /**
     * Fetch video information without creating a download
     */
    public function fetchInfo(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'youtube_url' => 'required|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Validate YouTube URL
        if (!$this->youtubeService->validateUrl($request->youtube_url)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid YouTube URL'
            ], 422);
        }

        try {
            // Extract video info
            $videoInfo = $this->youtubeService->extractVideoInfo($request->youtube_url);

            return response()->json([
                'success' => true,
                'video' => $videoInfo,
                // Cleaned title and artist to pre-fill the tag fields
                'suggested' => TrackTitle::suggest($videoInfo, Setting::cleanTitles()),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch video info: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified download
     */
    public function show(Download $download): JsonResponse
    {
        return response()->json([
            'success' => true,
            'download' => $download
        ]);
    }

    /**
     * Remove the specified download
     */
    public function destroy(Download $download): JsonResponse
    {
        try {
            // Delete file if exists
            if ($download->file_path && file_exists($download->file_path)) {
                unlink($download->file_path);
            }

            $download->delete();

            return response()->json([
                'success' => true,
                'message' => 'Download deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete download: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retry a failed download
     */
    public function retry(Download $download): JsonResponse
    {
        if (!in_array($download->status, ['failed', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only failed or cancelled downloads can be retried'
            ], 422);
        }

        try {
            $download->update([
                'status' => 'pending',
                'status_text' => 'Queued for retry',
                'error_message' => null,
                'progress' => 0,
                'conversion_progress' => 0,
            ]);

            // YouTube download or file conversion
            dispatch($download->job());

            return response()->json([
                'success' => true,
                'message' => 'Download retry started',
                'download' => $download
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retry download: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel a pending or processing download
     */
    public function cancel(Download $download): JsonResponse
    {
        if (!in_array($download->status, ['pending', 'processing'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending or processing downloads can be cancelled'
            ], 422);
        }

        try {
            $download->update([
                'status' => 'cancelled',
                'status_text' => 'Cancelled',
                'error_message' => 'Cancelled by user'
            ]);

            // A running job notices the status change at its next progress update and stops
            $download->syncPlaylistProgress();

            return response()->json([
                'success' => true,
                'message' => 'Download cancelled successfully',
                'download' => $download
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel download: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Stream/download the audio file
     */
    public function stream(Download $download): BinaryFileResponse
    {
        if ($download->status !== 'completed' || !$download->file_path) {
            abort(404, 'File not found');
        }

        if (!file_exists($download->file_path)) {
            abort(404, 'File not found on disk');
        }

        $mimeTypes = [
            'mp3' => 'audio/mpeg',
            'flac' => 'audio/flac',
            'wav' => 'audio/wav',
        ];

        // BinaryFileResponse supports HTTP range requests, so players can seek
        return response()->download(
            $download->file_path,
            $this->downloadFilename($download),
            ['Content-Type' => $mimeTypes[$download->format] ?? 'application/octet-stream']
        );
    }

    /**
     * Cover picture taken from a converted file (YouTube downloads link to YouTube's thumbnail)
     */
    public function cover(Download $download): BinaryFileResponse
    {
        abort_unless(is_file($download->coverPath()), 404);

        return response()->file($download->coverPath(), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=31536000',
        ]);
    }

    /**
     * Delete the original of a converted file on this computer, once the user chose to
     * after the conversion. Only the file that was converted can be removed this way.
     */
    public function destroyOriginal(Request $request, Download $download): JsonResponse
    {
        abort_unless(LocalFileAccess::allowed($request), 403, LocalFileAccess::DENIED);

        if ($download->source !== Download::SOURCE_LOCAL || $download->status !== 'completed') {
            return response()->json(['message' => 'Only originals of finished conversions can be deleted.'], 422);
        }

        if (!$download->original_available) {
            $download->update(['source_path' => null]);

            return response()->json(['message' => 'The original file is already gone.', 'download' => $download->fresh()]);
        }

        if (!@unlink($download->source_path)) {
            return response()->json(['message' => 'The original could not be deleted. Is it open in another app?'], 409);
        }

        $download->update(['source_path' => null]);

        return response()->json(['message' => 'Original deleted.', 'download' => $download->fresh()]);
    }

    /**
     * Lyrics for the player. Looked up once on first request (downloads made before
     * lyrics existed, or when the lookup failed); ?refresh=1 looks again.
     */
    public function lyrics(Request $request, Download $download, LyricsService $lyricsService, MetadataService $metadataService): JsonResponse
    {
        if ($download->status !== 'completed') {
            return response()->json(['message' => 'The download has not finished.'], 404);
        }

        if ($download->lyrics_status === null || $request->boolean('refresh')) {
            $fields = $lyricsService->lookup($download);

            if (!$fields && $download->lyrics_status === null) {
                return response()->json(['message' => 'The lyrics service could not be reached. Try again later.'], 503);
            }

            if ($fields) {
                $download->update($fields);

                // Best effort: a file that is playing may be locked (Windows); the
                // lyrics are in the database either way
                if ($download->lyrics && $download->file_path && is_file($download->file_path)
                    && $metadataService->writeLyrics($download->file_path, $download->lyrics)) {
                    clearstatcache(true, $download->file_path);
                    $download->update(['file_size' => filesize($download->file_path)]);
                }
            }
        }

        return response()->json([
            'status' => $download->lyrics_status,
            'lyrics' => $download->lyrics,
            'synced' => $download->synced_lyrics,
        ]);
    }

    /**
     * Open the file's folder on this computer, with the file selected (History → folder button)
     */
    public function reveal(Request $request, Download $download, FileExplorer $explorer): JsonResponse
    {
        if (!FileExplorer::available($request)) {
            return response()->json(['message' => 'Folders can only be opened from a browser on the computer running AudioFetch.'], 403);
        }

        if (!$download->file_path || !is_file($download->file_path)) {
            return response()->json(['message' => 'The file is no longer on disk. It may have been moved or deleted.'], 404);
        }

        $explorer->reveal($download->file_path);

        return response()->json(['message' => 'Opened the folder']);
    }

    /**
     * A ringtone from part of a song, saved as MP3 (History → Make ringtone)
     */
    public function ringtone(Request $request, Download $download, AudioConverterService $converter): BinaryFileResponse|JsonResponse
    {
        $data = $request->validate([
            'start' => 'required|numeric|min:0',
            'length' => 'required|numeric|min:' . self::RINGTONE_MIN . '|max:' . self::RINGTONE_MAX,
            'fade' => 'nullable|boolean',
        ]);

        if ($download->status !== 'completed' || !$download->file_path || !is_file($download->file_path)) {
            return response()->json(['message' => 'The audio file is not available.'], 404);
        }

        $start = (float) $data['start'];
        $length = (float) $data['length'];

        if ($download->duration) {
            if ($start + 1 > $download->duration) {
                throw ValidationException::withMessages(['start' => 'The start is after the end of the song.']);
            }
            $length = min($length, $download->duration - $start);
        }

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $output = $tempDir . DIRECTORY_SEPARATOR . 'ringtone_' . Str::random(16) . '.mp3';

        $result = Process::timeout(120)->run($converter->ringtoneCommand(
            $download->file_path,
            $output,
            $start,
            $length,
            $request->boolean('fade', true),
            ['title' => TrackTitle::songTitle($download->title, $download->artist) . ' (Ringtone)', 'artist' => $download->artist]
        ));

        if (!$result->successful() || !is_file($output)) {
            @unlink($output);

            return response()->json(['message' => 'Could not make the ringtone: ' . ProcessOutput::error($result)], 500);
        }

        $name = pathinfo($this->downloadFilename($download), PATHINFO_FILENAME);

        return response()->download($output, "{$name} (Ringtone).mp3", ['Content-Type' => 'audio/mpeg'])->deleteFileAfterSend();
    }

    /**
     * Several finished downloads in one ZIP file (History → Select → Download ZIP)
     */
    public function zip(Request $request): BinaryFileResponse|JsonResponse
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($id) => (int) trim($id))
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty() || $ids->count() > self::ZIP_LIMIT) {
            return response()->json(['message' => 'Choose between 1 and ' . self::ZIP_LIMIT . ' songs.'], 422);
        }

        if (!class_exists(\ZipArchive::class)) {
            return response()->json(['message' => 'The PHP zip extension is not installed on this server.'], 500);
        }

        // Keep the order the songs were selected in
        $downloads = Download::completed()->whereIn('id', $ids)->get()
            ->filter(fn (Download $download) => $download->file_path && is_file($download->file_path))
            ->sortBy(fn (Download $download) => $ids->search($download->id))
            ->values();

        if ($downloads->isEmpty()) {
            return response()->json(['message' => 'None of these files exist anymore.'], 404);
        }

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $path = $tempDir . DIRECTORY_SEPARATOR . 'zip_' . Str::random(16) . '.zip';
        $zip = new \ZipArchive();

        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['message' => 'Could not create the ZIP file.'], 500);
        }

        $used = [];
        foreach ($downloads as $download) {
            $name = $this->uniqueName($this->downloadFilename($download), $used);
            $zip->addFile($download->file_path, $name);
            // Audio is already compressed: storing is as small and much faster
            $zip->setCompressionName($name, \ZipArchive::CM_STORE);
        }

        if (!$zip->close()) {
            @unlink($path);

            return response()->json(['message' => 'Could not create the ZIP file.'], 500);
        }

        $count = $downloads->count();
        $fileName = config('app.name') . ' - ' . $count . ' song' . ($count === 1 ? '' : 's') . ' ' . now()->format('Y-m-d') . '.zip';

        return response()->download($path, $fileName, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /**
     * "Song.mp3", then "Song (2).mp3" for a second song with the same name
     */
    private function uniqueName(string $name, array &$used): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $candidate = $name;

        for ($i = 2; isset($used[mb_strtolower($candidate)]); $i++) {
            $candidate = "{$base} ({$i}).{$extension}";
        }

        $used[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    /**
     * File name offered to the browser: the video title, like the file on disk
     */
    private function downloadFilename(Download $download): string
    {
        $fallback = pathinfo($download->file_path, PATHINFO_FILENAME);

        $name = $download->title === Download::PLACEHOLDER_TITLE
            ? $fallback
            : FileName::fromTitle($download->title, $fallback);

        return $name . '.' . $download->format;
    }
}
