<?php

namespace App\Http\Controllers;

use App\Models\Download;
use App\Jobs\DownloadYoutubeAudioJob;
use App\Services\YouTubeService;
use App\Models\Setting;
use App\Support\FileName;
use App\Support\TrackTitle;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadController extends Controller
{
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
        ]);

        $validator->after(function ($validator) use ($request) {
            if ($request->filled('trim_end') && (int) $request->trim_end <= (int) $request->trim_start) {
                $validator->errors()->add('trim_end', 'The end time must be after the start time.');
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

            $edited = $request->filled('trim_start') || $request->filled('trim_end') || $request->boolean('remove_silence');

            // Reuse an earlier download only if it has the same format/quality, was not
            // trimmed, and its file still exists. Edited downloads are always made fresh.
            $existing = $edited ? null : Download::where('youtube_id', $videoId)
                ->where('status', 'completed')
                ->where('format', $request->format)
                ->where('quality', (string) $request->quality)
                ->whereNull('trim_start')
                ->whereNull('trim_end')
                ->where('remove_silence', false)
                ->latest()
                ->get()
                ->first(fn (Download $download) => $download->file_path && file_exists($download->file_path));

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

            DownloadYoutubeAudioJob::dispatch($download);

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
