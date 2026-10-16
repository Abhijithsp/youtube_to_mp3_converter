<?php

namespace App\Services;

use App\Models\Download;
use App\Support\TrackTitle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Song lyrics from LRCLIB (https://lrclib.net): free, no API key, and often
 * with timed (synced) lines that the player can highlight while a song plays.
 */
class LyricsService
{
    private const API = 'https://lrclib.net/api';

    /**
     * Seconds a match may differ in length from the song
     */
    private const DURATION_TOLERANCE = 4;

    /**
     * ['status' => found|none|instrumental, 'lyrics' => plain text, 'synced' => LRC text]
     * or null when LRCLIB could not be reached (try again later).
     */
    public function find(string $title, ?string $artist, ?string $album = null, ?int $duration = null): ?array
    {
        // "Artist - Song (Official Video)" → "Song"
        $track = trim(TrackTitle::songTitle(TrackTitle::clean($title), $artist));
        $artist = trim((string) $artist);

        if ($track === '') {
            return $this->none();
        }

        try {
            // Exact lookup first (needs artist and duration), then a search
            if ($artist !== '' && $duration) {
                $response = $this->client()->get(self::API . '/get', array_filter([
                    'track_name' => $track,
                    'artist_name' => $artist,
                    'album_name' => $album ?: null,
                    'duration' => $duration,
                ]));

                if ($response->successful() && is_array($response->json())) {
                    return $this->result($response->json());
                }
                if ($response->serverError()) {
                    return null;
                }
            }

            // Then searches: by track and artist, then loosely by the main artist and track
            // ("Sound Rush ft. Armin van Buuren" is often listed as just "Sound Rush")
            $searches = $artist !== ''
                ? [['track_name' => $track, 'artist_name' => $artist], ['q' => $this->mainArtist($artist) . ' ' . $track]]
                : [['q' => $track]];

            foreach ($searches as $query) {
                $response = $this->client()->get(self::API . '/search', $query);

                if ($response->serverError()) {
                    return null;
                }

                $match = collect($response->successful() ? ($response->json() ?: []) : [])
                    ->filter(fn ($item) => is_array($item))
                    ->first(fn (array $item) => !$duration
                        || !isset($item['duration'])
                        || abs((float) $item['duration'] - $duration) <= self::DURATION_TOLERANCE);

                if ($match) {
                    return $this->result($match);
                }
            }

            return $this->none();
        } catch (Throwable $e) {
            Log::info('Lyrics lookup failed', ['title' => $title, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Look up and store lyrics for a download (fields for Download::update), or [] when offline
     */
    public function lookup(Download $download): array
    {
        $found = $this->find($download->title, $download->artist, $download->album, $download->duration);

        if ($found === null) {
            return [];
        }

        return [
            'lyrics' => $found['lyrics'],
            'synced_lyrics' => $found['synced'],
            'lyrics_status' => $found['status'],
        ];
    }

    /**
     * "Sound Rush ft. Armin van Buuren & Sharon den Adel" → "Sound Rush"
     */
    private function mainArtist(string $artist): string
    {
        return trim(preg_split('/\s+(?:ft\.?|feat\.?|featuring|x|vs\.?)\s+|\s*[&,]\s*/i', $artist)[0] ?? $artist);
    }

    private function result(array $item): array
    {
        if (!empty($item['instrumental'])) {
            return ['status' => 'instrumental', 'lyrics' => null, 'synced' => null];
        }

        $plain = trim((string) ($item['plainLyrics'] ?? ''));
        $synced = trim((string) ($item['syncedLyrics'] ?? ''));

        // Plain text can be rebuilt from timed lines when only those exist
        if ($plain === '' && $synced !== '') {
            $plain = trim(preg_replace('/^\[[\d:.]+\]\s?/m', '', $synced));
        }

        if ($plain === '') {
            return $this->none();
        }

        return ['status' => 'found', 'lyrics' => $plain, 'synced' => $synced !== '' ? $synced : null];
    }

    private function none(): array
    {
        return ['status' => 'none', 'lyrics' => null, 'synced' => null];
    }

    private function client()
    {
        return Http::timeout(10)
            ->acceptJson()
            ->withUserAgent('AudioFetch (https://github.com/Abhijithsp/youtube_to_mp3_converter)')
            // LRCLIB is often briefly overloaded ("503 server busy"): try twice more,
            // but never for answers like "not found"
            ->retry(3, 1500, fn ($exception) => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && in_array($exception->response->status(), [429, 502, 503, 504], true)), throw: false);
    }
}
