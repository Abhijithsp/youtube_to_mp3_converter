# API Documentation

## Queue Management

### Get Queue Status

**GET** `/api/queue/status`
Returns counts of pending, processing, and failed jobs.

### List Active Jobs

**GET** `/api/queue/jobs`
Returns detailed list of currently active or pending jobs.

### List Failed Jobs

**GET** `/api/queue/failed`
Returns list of failed jobs with error messages.

### Actions

-   **POST** `/api/queue/retry-all` - Retry all failed jobs.
-   **POST** `/api/queue/failed/{id}/retry` - Retry specific job.
-   **POST** `/api/queue/jobs/{id}/cancel` - Cancel active job.
-   **POST** `/api/queue/clear-failed` - Remove failed jobs from list.

---

## Playlists

### Fetch Info

**POST** `/api/playlists/fetch-info`
Body: `{ "url": "..." }`
Returns playlist metadata and video list without saving.

### Import Playlist

**POST** `/api/playlists`
Body:

```json
{
  "url": "...",
  "title": "...",
  "format": "mp3",
  "quality": "320",
  "items": [ ... ]
}
```

Creates playlist and dispatches download jobs.

---

## History & Downloads

### Get History

**GET** `/api/history`
Query Params: `page`, `search`, `format`, `sort_by`

### Clear History

**DELETE** `/api/history`
Query Params: `keep_files=true|false`

### Single Download

**POST** `/api/downloads`
Body: `{ "url": "...", "format": "...", "quality": ... }`

---

## Settings

### Get Settings

**GET** `/api/settings`

### Update Settings

**PUT** `/api/settings`
Body:

```json
{
    "download_path": "C:/Music",
    "max_playlist_size": 50,
    "rate_limit_seconds": 2,
    "min_disk_space_mb": 500,
    "ytdlp_cookies_browser": "firefox",
    "ytdlp_cookies_file": ""
}
```

### System Checks

**GET** `/api/settings/test-dependencies`
Returns installation status of `yt-dlp` and `ffmpeg`.

**GET** `/api/settings/test-youtube`
Makes one YouTube request with the saved cookie settings and returns `{ ok, message, cookies }`.

**GET** `/api/settings/setup-checks`
First-run checklist: `{ ready, checks: [{ key, label, required, ok, detail, fix }] }` for yt-dlp, FFmpeg, Deno, the queue worker, the download folder and disk space.

**GET** `/api/settings/yt-dlp`
Installed and latest yt-dlp version: `{ installed, latest, update_available }`.

**POST** `/api/settings/yt-dlp/update`
Updates yt-dlp (self-update, or winget when installed with winget). Returns `{ ok, updated, version, message, output }`.

### Search

**GET** `/api/search?q=song name`
Searches YouTube (no API key needed). Returns `{ results: [{ id, title, url, channel, duration, views, thumbnail }] }`. Results are cached for 30 minutes.

### Download options

`POST /api/downloads` also accepts:

```json
{
    "format": "mp3 | flac | wav",
    "trim_start": 15,
    "trim_end": 180,
    "remove_silence": true,
    "title": "D-Sturb - LADY",
    "artist": "D-Sturb",
    "year": 2019,
    "genre": "Hardstyle"
}
```

`POST /api/downloads/fetch-info` returns `suggested: { title, artist }`, a cleaned-up title and the artist parsed from it.
