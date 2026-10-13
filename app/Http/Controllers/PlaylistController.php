<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Services\YouTubeService;
use App\Jobs\ProcessPlaylistJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;

class PlaylistController extends Controller
{
    public function __construct(
        protected YouTubeService $youtubeService
    ) {}

    /**
     * Fetch playlist info without saving
     */
    public function fetchInfo(Request $request)
    {
        $request->validate([
            'url' => 'required|url',
        ]);

        try {
            $info = $this->youtubeService->extractPlaylistInfo($request->url);
            return response()->json($info);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch playlist info',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Create playlist and import items
     */
    public function store(Request $request)
    {
        $request->validate([
            'url' => 'required|url',
            'title' => 'required|string',
            'items' => 'required|array', // Selected items
            'items.*.url' => 'required|url',
            'items.*.title' => 'required|string',
            'items.*.id' => 'required|string',
            'format' => 'required|in:mp3,flac',
            'quality' => 'required|in:192,256,320',
        ]);

        $maxSize = (int) \App\Models\Setting::get('max_playlist_size', config('youtube-converter.max_playlist_size', 50));
        if (count($request->items) > $maxSize) {
            return response()->json([
                'message' => "Playlist size exceeds limit of {$maxSize} videos.",
            ], 422);
        }

        // Channel/upload-list URLs have no list= parameter, so key them by URL instead
        $playlistId = $this->youtubeService->extractPlaylistId($request->url)
            ?? 'url-' . sha1($request->url);

        try {
            $playlist = DB::transaction(function () use ($request, $playlistId) {
                // youtube_playlist_id is unique: importing the same playlist again
                // replaces its item list instead of failing on the constraint
                $playlist = Playlist::firstOrNew(['youtube_playlist_id' => $playlistId]);
                $playlist->fill([
                    'title' => $request->title,
                    'total_videos' => count($request->items),
                    'processed_videos' => 0,
                    'status' => 'pending',
                ])->save();

                $playlist->items()->delete();

                // Create Item records
                foreach (array_values($request->items) as $index => $item) {
                    PlaylistItem::create([
                        'playlist_id' => $playlist->id,
                        'youtube_url' => $item['url'],
                        'position' => $index + 1,
                        'selected' => true,
                    ]);
                }

                return $playlist;
            });

            // Dispatch after commit so a worker never picks the job up before the rows exist
            ProcessPlaylistJob::dispatch(
                $playlist,
                $request->format,
                (int) $request->quality
            );

            return response()->json([
                'message' => 'Playlist processing started',
                'playlist' => $playlist->load('items'),
            ], 201);

        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to queue playlist',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function index()
    {
        $playlists = Playlist::withCount('items')
            ->orderBy('created_at', 'desc')
            ->paginate(10);
            
        return response()->json($playlists);
    }

    public function show(Playlist $playlist)
    {
        return response()->json(
            $playlist->load(['items.download'])
        );
    }

    public function destroy(Playlist $playlist)
    {
        $playlist->delete();
        return response()->json(['message' => 'Playlist deleted']);
    }
}
