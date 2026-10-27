# AudioFetch: Architecture and Flow

This document explains how AudioFetch works from end to end: the parts it is made of, how a request travels through them, and what every module and function does. Each flow comes with a diagram and a worked example.

It describes the code as of 2026-10-27: commit `4fb600c` ("Tidy song lists and move songs to a new folder") plus the dark-mode colours of section 12.7. That includes the fixes listed in section 17.

**How to read the examples.** Commands, endpoints, field names, progress numbers and file-name rules are taken directly from the code. Values such as IDs, sizes and times are illustrative, chosen to show the rules at work.

---

## Contents

1. [What AudioFetch is](#1-what-audiofetch-is)
2. [Technology stack](#2-technology-stack)
3. [System architecture](#3-system-architecture)
4. [Running processes](#4-running-processes)
5. [Project layout](#5-project-layout)
6. [Request lifecycle](#6-request-lifecycle)
7. [Data model](#7-data-model)
8. [Download status and progress](#8-download-status-and-progress)
9. [Backend modules and functions](#9-backend-modules-and-functions)
10. [API reference](#10-api-reference)
11. [Flows with real examples](#11-flows-with-real-examples)
12. [Frontend architecture](#12-frontend-architecture)
13. [Configuration](#13-configuration)
14. [Security design](#14-security-design)
15. [Deployment](#15-deployment)
16. [Testing](#16-testing)
17. [Notes found while reviewing the code](#17-notes-found-while-reviewing-the-code)

---

## 1. What AudioFetch is

AudioFetch is a self-hosted web app that turns YouTube videos, and your own video or audio files, into tagged MP3, FLAC or WAV files. It runs on your own computer or server and is used from a browser on a PC or phone.

What it can do:

- **Get audio from YouTube.** Paste one link, many links or a playlist, or search by song name.
- **Convert your own files.** Upload them from the browser, or pick them from a folder on the computer that runs the app.
- **Edit while converting.** Trim, remove silence, even out the volume, and split a video into tracks by its chapters.
- **Tag automatically.** Title, artist, album, year, genre, cover art and lyrics (from LRCLIB) are written into the file.
- **Manage the library.** History, a built-in player with a waveform seek bar and timed lyrics, tag editing, ringtone maker, ZIP download and "show in folder".
- **Run on a server.** An optional password lock, a Docker image and an installable app (PWA) with system notifications.

The heavy work never happens inside a web request. The browser asks for a download, the server saves a row in the database and puts a job in a queue, and a separate **queue worker** runs `yt-dlp` and `ffmpeg`. The browser polls for progress.

---

## 2. Technology stack

| Layer | Technology | Used for |
| --- | --- | --- |
| Backend framework | Laravel 13.38 (PHP 8.3) | HTTP API, queue, scheduler, ORM, validation |
| Database | MySQL / MariaDB (SQLite in tests and Docker) | Downloads, playlists, settings, queue jobs, cache |
| Queue | Laravel database queue (`jobs`, `failed_jobs` tables) | Running downloads and conversions in the background |
| Frontend | Vue 3 (Options API), Vue Router 4 | Single-page app |
| Styling | Tailwind CSS v4, Roboto variable font, Material Design Icons (`@mdi/js`) | Look and theming |
| Build | Vite 7 with `laravel-vite-plugin` | Bundling the SPA |
| HTTP client (browser) | axios | Calls to `/api/*` |
| YouTube | `yt-dlp` (with Deno as its JavaScript runtime) | Video info, search, playlists, audio download |
| Audio | `ffmpeg`, `ffprobe` | Conversion, trimming, tags, cover art, waveform, chapter cuts, ringtones |
| Lyrics | LRCLIB (`https://lrclib.net/api`) | Plain and timed lyrics |
| Updates | GitHub API | Newest yt-dlp version |

---

## 3. System architecture

### 3.1 Big picture

```text
                       ┌──────────────────────────────────────────────┐
                       │  Browser (PC or phone)                       │
                       │  Vue 3 SPA · player.js · notifications.js    │
                       │  service worker (sw.js) · localStorage       │
                       └───────────────┬──────────────────────────────┘
                     JSON over HTTP    │   audio stream (HTTP range)
                     /api/*            │   ZIP / ringtone download
                                       ▼
┌────────────────────────────────────────────────────────────────────────────┐
│ Laravel web server  (php artisan serve · Apache/Laragon · Docker)          │
│                                                                            │
│  routes/web.php ── catch-all ──► resources/views/app.blade.php (SPA shell) │
│  routes/api.php ── RequireAppPassword ──► Controllers ──► Services         │
│                                              │                             │
│                                              │ Download::create()          │
│                                              │ Job::dispatch()             │
└──────────────────────────────────────────────┼─────────────────────────────┘
                                               ▼
                    ┌──────────────────────────────────────────┐
                    │ Database (MySQL)                         │
                    │ downloads · playlists · playlist_items   │
                    │ settings · jobs · failed_jobs · cache    │
                    └──────────────────────┬───────────────────┘
                                           │ polls the jobs table
                                           ▼
┌────────────────────────────────────────────────────────────────────────────┐
│ Queue worker  (php artisan queue:work / queue:listen)                      │
│                                                                            │
│  DownloadYoutubeAudioJob · ConvertLocalFileJob · ProcessPlaylistJob        │
│  CleanupOldDownloadsJob (daily, from the scheduler)                        │
│        │                │                 │                                │
│        ▼                ▼                 ▼                                │
│   yt-dlp (+Deno)   ffmpeg/ffprobe    HTTP: LRCLIB, thumbnails              │
└────────┬───────────────┬───────────────────────────────────────────────────┘
         │               │
         ▼               ▼
   YouTube         File system
                   ├─ download folder (Settings → Storage)   finished songs
                   ├─ storage/app/temp                       work files
                   ├─ storage/app/uploads/<uuid>/            browser uploads
                   └─ storage/app/covers/<id>.jpg            covers of converted files
```

### 3.2 Layers inside the backend

```text
 HTTP request
     │
     ▼
 Middleware ............ RequireAppPassword (password lock), throttle on unlock
     │
     ▼
 Controllers ........... validate input, read/write models, dispatch jobs, return JSON
     │
     ├──► Models ......... Download, Playlist, PlaylistItem, Setting (Eloquent)
     │
     ├──► Jobs ........... long work on the queue worker
     │        │
     │        ▼
     └──► Services ....... YouTubeService, AudioConverterService, MetadataService,
              │            LocalFileService, LyricsService, ChapterSplitService,
              │            WaveformService, DiskSpaceService
              ▼
          Support ........ TrackTitle, FileName, UploadStore, AppLock, LocalFileAccess,
                           FileExplorer, ProcessOutput, QueueWorkerHeartbeat
              │
              ▼
          External tools . yt-dlp, ffmpeg, ffprobe, deno, explorer.exe / open / xdg-open
```

Rules the layers follow:

- **Controllers stay quick.** Anything that takes seconds or more (download, convert, playlist expansion) is a job. Exceptions are short tasks: fetching video info, search, inspecting files, tag edits, ringtones and ZIPs.
- **Services hold the tool knowledge.** Only services build `yt-dlp` and `ffmpeg` command lines.
- **Commands are arrays.** Every external tool is started with an argument array (`Process::run([...])`), never a shell string. Spaces, quotes and `&` in file names cannot break or inject into a command.

---

## 4. Running processes

AudioFetch needs up to four processes running at the same time:

| Process | Command | Role |
| --- | --- | --- |
| Web server | `php artisan serve`, Apache (Laragon) or `php artisan serve` in Docker | Serves the SPA and the API |
| Queue worker | `php artisan queue:work` or `queue:listen` | Runs jobs: downloads, conversions, playlists |
| Scheduler | `php artisan schedule:work` (or cron `schedule:run`) | Runs `CleanupOldDownloadsJob` daily |
| Vite (development only) | `npm run dev` | Hot-reloads the frontend |

How they are started:

- **`composer run dev`** starts the server, `queue:listen --tries=1`, `pail` (logs) and Vite together through `npx concurrently`.
- **`php artisan dev`** (Laravel 13.25+) does the same. On Windows it also uses `concurrently`.
- **Docker** (`docker/entrypoint.sh`) runs migrations, seeds settings once, then loops `queue:work --sleep=2 --tries=3` and `schedule:work` in the background and runs `php artisan serve` in the foreground.

**How the app knows the worker is running.** Every time a worker polls the queue, Laravel fires `JobPopping`. `AppServiceProvider` listens for it and calls `QueueWorkerHeartbeat::beat()`, which stores a timestamp in the cache. `isAlive()` is true when the last beat is at most 30 seconds old. It is also true when a download is `processing` and was updated in the last 120 seconds, because a worker busy with a long job does not poll. The UI shows `WorkerBanner` when the worker is down and jobs are waiting.

---

## 5. Project layout

```text
app/
├─ Console/Commands/      GenerateWaveforms (downloads:waveforms), HashPassword (app:hash-password)
├─ Exceptions/            AudioConversion, DownloadCancelled, InsufficientDiskSpace, YouTubeDownload
├─ Http/
│  ├─ Controllers/        Download, FileConvert, Folder, History, Dashboard, Playlist,
│  │                      Queue, Settings, Lock
│  └─ Middleware/         RequireAppPassword
├─ Jobs/                  DownloadYoutubeAudioJob, ConvertLocalFileJob,
│                         ProcessPlaylistJob, CleanupOldDownloadsJob
├─ Models/                Download, Playlist, PlaylistItem, Setting, User
├─ Providers/             AppServiceProvider (worker heartbeat)
├─ Services/              YouTube, AudioConverter, Metadata, LocalFile, Lyrics,
│                         ChapterSplit, Waveform, DiskSpace
└─ Support/               TrackTitle, FileName, UploadStore, AppLock, LocalFileAccess,
                          FileExplorer, ProcessOutput, QueueWorkerHeartbeat
bootstrap/app.php         Routing, middleware priority, JSON errors for /api
config/youtube-converter.php   App settings and their .env names
database/migrations/      Schema (see section 7)
database/seeders/         SettingsSeeder (default settings)
routes/
├─ api.php                All /api endpoints
├─ web.php                Catch-all route that serves the SPA
└─ console.php            Daily cleanup schedule
resources/
├─ views/app.blade.php    SPA shell, sets theme and accent before first paint
├─ css/app.css            Tailwind and design tokens
└─ js/
   ├─ app.js              Creates the Vue app, registers the service worker
   ├─ router/index.js     Six pages
   ├─ services/api.js     All API calls (axios)
   ├─ ui.js               Shared UI state and helpers
   ├─ player.js           Shared audio player
   ├─ notifications.js    System notifications
   ├─ App.vue             Shell: navigation, player bar, dialogs, queue polling
   ├─ views/              Dashboard, SingleDownload, PlaylistImport, QueueStatus,
   │                      DownloadHistory, Settings
   └─ components/         21 reusable components (section 12.4)
public/sw.js, manifest.json    Installable app (PWA)
docker/, Dockerfile, docker-compose.yml   Container setup
tests/                    Unit, Feature and Integration tests
```

---

## 6. Request lifecycle

### 6.1 Loading the app

```text
Browser GET /history
   │
   ▼
public/index.php → bootstrap/app.php
   │
   ▼
routes/web.php:  Route::get('/{any}') → view('app')       (every non-API path)
   │
   ▼
app.blade.php
   ├─ inline script: reads localStorage "theme" and "accent" and applies them
   │                 before the first paint (no flash of the wrong theme)
   └─ @vite: loads resources/css/app.css and resources/js/app.js
   │
   ▼
app.js: createApp(App).use(router).mount('#app'), registers /sw.js
   │
   ▼
Vue Router shows the DownloadHistory view for /history
```

### 6.2 An API call

Example: the History page loads `GET /api/history?page=1&sort_by=completed_at&sort_dir=desc&per_page=25`.

```text
axios (services/api.js, baseURL "/api", Accept: application/json)
   │
   ▼
bootstrap/app.php
   ├─ RequireAppPassword is placed in front of SubstituteBindings, so a locked app
   │  answers 401 before any {download} model is looked up (nothing leaks)
   └─ errors for api/* are rendered as JSON
   │
   ▼
routes/api.php → middleware RequireAppPassword
   ├─ no APP_PASSWORD set ............................... continue
   ├─ cookie yc_unlock matches the HMAC token ........... continue
   └─ otherwise → 401 {"message": "This app is locked...", "locked": true}
   │
   ▼
HistoryController@index → Download::completed()->filters->paginate(25)
   │
   ▼
JSON: Laravel paginator fields + "can_open_folder": true|false
   │
   ▼
api.js response interceptor: on 401 with locked=true sets ui.locked = true,
which makes App.vue show the LockScreen
```

---

## 7. Data model

### 7.1 Tables

```text
┌──────────────────────────────┐        ┌────────────────────────────┐
│ downloads                    │        │ playlists                  │
├──────────────────────────────┤        ├────────────────────────────┤
│ id                           │◄──┐    │ id                         │
│ user_id → users (nullable)   │   │    │ youtube_playlist_id UNIQUE │
│ source  youtube|upload|local │   │    │ title                      │
│ source_path, source_name     │   │    │ total_videos               │
│ youtube_url, youtube_id      │   │    │ processed_videos           │
│ title, artist, album         │   │    │ status pending|processing| │
│ year, genre, thumbnail_url   │   │    │        completed|failed    │
│ format  mp3|flac|wav         │   │    └─────────────┬──────────────┘
│ quality 192|256|320          │   │                  │ 1
│ trim_start, trim_end         │   │                  │
│ remove_silence               │   │                  │ n
│ normalize_volume             │   │    ┌─────────────▼──────────────┐
│ split_chapters, chapter_index│   │    │ playlist_items             │
│ file_path, file_size         │   │    ├────────────────────────────┤
│ original_file_size, duration │   └────┤ download_id (nullable,     │
│ waveform (JSON)              │        │   set null on delete)      │
│ lyrics, synced_lyrics        │        │ playlist_id (cascade)      │
│ lyrics_status                │        │ youtube_url, position      │
│ status, status_text          │        │ selected                   │
│ error_message                │        └────────────────────────────┘
│ progress, conversion_progress│
│ completed_at, timestamps     │        ┌────────────────────────────┐
└──────────────────────────────┘        │ settings                   │
                                        ├────────────────────────────┤
 Framework tables: users, jobs,         │ key UNIQUE, value (text)   │
 failed_jobs, job_batches, cache,       └────────────────────────────┘
 cache_locks, sessions, password_reset_tokens
```

### 7.2 The `downloads` table, column by column

| Column | Meaning |
| --- | --- |
| `source` | Where the audio came from: `youtube`, `upload` (sent from the browser) or `local` (a file on the computer running the app). Default `youtube`. |
| `source_path` | The file that was converted. Hidden from API output. Set to `null` once an upload is deleted after conversion, or once a local original is deleted. |
| `source_name` | Original file name, shown in the UI ("Converted from holiday.mp4"). |
| `youtube_url`, `youtube_id` | Video link and 11-character ID. Nullable since converted files have none. |
| `title`, `artist`, `album`, `year`, `genre` | Tags. `title` starts as `Fetching...` (`Download::PLACEHOLDER_TITLE`) when yt-dlp has not been asked yet. |
| `thumbnail_url` | YouTube thumbnail, or `/api/downloads/{id}/cover?v=<mtime>` for converted files. |
| `format`, `quality` | `mp3`/`flac`/`wav`; bitrate `192`/`256`/`320` (only used by MP3). |
| `trim_start`, `trim_end` | Optional cut in seconds. |
| `remove_silence`, `normalize_volume` | Audio edits applied while converting. |
| `split_chapters`, `chapter_index` | Split into chapter tracks; position of this track (1, 2, …). |
| `file_path`, `file_size` | The finished file and its size in bytes. |
| `original_file_size` | Size of the raw download or of the source file. |
| `duration` | Length in seconds (updated to the real length after trimming). |
| `waveform` | 160 loudness values (0–100) for the seek bar. |
| `lyrics`, `synced_lyrics`, `lyrics_status` | Plain text, LRC timed lines, and `null` (not looked up yet), `found`, `none` or `instrumental`. |
| `status`, `status_text`, `error_message` | State, human-readable step ("Downloading... 42% (3.10MiB)"), and why it failed. |
| `progress`, `conversion_progress` | Overall progress 0–100, and conversion progress 0–100. |
| `completed_at` | When it finished. History sorts by this. |

The old `download_url` column was never written and was dropped on 2026-10-27. The `download_url` in API output is the computed attribute below.

### 7.3 Computed attributes on `Download`

- **`download_url`**: `route('downloads.stream', $download)` when the status is `completed` and a file exists, otherwise `null`.
- **`original_available`**: `true` when `source` is `local`, `source_path` differs from `file_path`, and the original file still exists. The UI uses it to offer "Delete original file".

### 7.4 Migration history

| Migration | Change |
| --- | --- |
| `0001_01_01_*` | Laravel defaults: users, cache, jobs (plus `job_batches`, `failed_jobs`) |
| `2025_12_26_161657` | `downloads` (format `mp3`/`flac`) |
| `2025_12_26_161710/725/732` | `playlists`, `playlist_items`, `settings` |
| `2026_06_01_115640` | `original_file_size` |
| `2026_06_01_122841` | `status_text`, `conversion_progress` |
| `2026_10_13_000001` | `wav` format, `trim_start`, `trim_end`, `remove_silence`, `year`, `genre` |
| `2026_10_15_000001` | `waveform` |
| `2026_10_16_000001` | `normalize_volume`, `split_chapters`, `chapter_index`, `lyrics`, `synced_lyrics`, `lyrics_status` |
| `2026_10_19_000001` | `source`, `source_path`, `source_name`; `youtube_url` and `youtube_id` become nullable |
| `2026_10_27_000001` | Drops the unused `download_url` column |

---

## 8. Download status and progress

### 8.1 Status state machine

```text
                     POST /api/downloads, /bulk, /files/convert, playlist job
                                         │
                                         ▼
                                  ┌────────────┐
       POST /{id}/retry ─────────►│  pending   │◄─────────────────────────┐
       (failed or cancelled)      └─────┬──────┘                          │
                                        │ worker starts the job           │ YouTube job only:
                                        ▼                                 │ attempt failed and
                                  ┌────────────┐                          │ attempts < tries
                                  │ processing ├──────────────────────────┘
                                  └──┬──────┬──┘
               all steps done        │      │  last attempt failed, timeout,
            ┌────────────────────────┘      │  or worker crash → failed()
            ▼                               ▼
     ┌────────────┐                   ┌────────────┐
     │ completed  │                   │   failed   │
     └────────────┘                   └────────────┘

     POST /{id}/cancel while pending or processing ──► cancelled
     (the running job sees it at its next progress update and stops)
```

`Download::FINAL_STATUSES` are `completed`, `failed` and `cancelled`. No job touches a download in a final state again.

### 8.2 Progress bar map

**YouTube download (`DownloadYoutubeAudioJob`)**

| Progress | `status_text` | Step |
| --- | --- | --- |
| 0 | Fetching video information... | Job starts, yt-dlp `--dump-json` if needed |
| 3 | (unchanged) | Info stored, disk space check |
| 5 | Starting download... | Paths prepared |
| 5 → 65 | Downloading... 42% (3.10MiB) | `5 + yt-dlp% × 0.6` |
| 65 | Download complete. Preparing for conversion... | Raw file found |
| 65 → 90 | Converting to mp3... 50% (4.20 MB) | `65 + ffmpeg% × 0.25` |
| 90 | Applying metadata tags... | Tags |
| 92 | Adding cover art... | Thumbnail embedded |
| 94 | Looking for lyrics... | LRCLIB (skipped for chapter splits) |
| 96 | Splitting into… tracks... | Chapter split (only when chosen) |
| 97 | Drawing waveform... | Waveform |
| 100 | Completed | Done |

**File conversion (`ConvertLocalFileJob`)**

| Progress | `status_text` | Step |
| --- | --- | --- |
| 0 | Reading file... | ffprobe inspection |
| 2 | (unchanged) | Duration stored, disk space check |
| 5 → 85 | Converting to mp3... 50% (4.20 MB) | `5 + ffmpeg% × 0.8` |
| 88 | Applying metadata tags... | Tags |
| 91 | Adding cover art... | Embedded art or a video frame |
| 94 | Looking for lyrics... | LRCLIB |
| 97 | Drawing waveform... | Waveform |
| 100 | Completed | Done |

`StatusChip.vue` turns `status_text` into a short chip label: Getting info, Reading file, Downloading, Converting, Tagging, Finding lyrics, Splitting, Finishing.

Progress is written once per whole percent. Every write also calls `abortIfCancelled()`, which is how cancelling stops a running job.

---

## 9. Backend modules and functions

### 9.1 Controllers

#### `DownloadController`

The main controller for YouTube downloads and for everything done with a finished song.

| Method | What it does |
| --- | --- |
| `index` | Lists downloads, newest first. Filters: `status` (comma list, e.g. `pending,processing`), `ids` (comma list), `search` (title or artist). `per_page` 1–100. |
| `store` | Validates a single download (URL, format, quality, tags, trim, edits). Rejects a trim combined with chapter splitting. Returns an existing download when the same video was already downloaded unedited in the same format and quality (`Download::findExisting`). Otherwise creates the row (`pending`) and dispatches `DownloadYoutubeAudioJob`. |
| `bulk` | Up to 50 links. Each link is checked (YouTube link? video ID? playlist? listed twice? already downloaded?) and is either queued or reported in `skipped` with a reason. Jobs are spaced `rate_limit_seconds` apart. |
| `fetchInfo` | Runs yt-dlp for video info and returns it with `suggested` title and artist (`TrackTitle::suggest`). |
| `search` | YouTube search by text, cached for 30 minutes per query. |
| `show` | One download. |
| `update` | Edits tags of a finished file: rewrites tags with `clearEmpty`, puts lyrics back, renames the file to the new title unless `rename=false`. Answers 409 when the file is locked. |
| `destroy` | Deletes the file and the row. |
| `retry` | Failed or cancelled → `pending`, and dispatches `$download->job()` (the right job for the source). |
| `cancel` | Pending or processing → `cancelled`, and updates the playlist counters. |
| `stream` | Sends the file with the right MIME type. `BinaryFileResponse` supports HTTP range requests, so the player can seek. |
| `cover` | Cover JPEG of a converted file. |
| `destroyOriginal` | Deletes the original of a local conversion (only when `LocalFileAccess` allows it). |
| `waveform` | Returns the waveform, generating and storing it on first request for older downloads. |
| `lyrics` | Returns lyrics, looking them up on first request; `?refresh=1` looks again. Writes them into the file when possible. |
| `reveal` | Opens the file's folder on the host (only from the same computer). |
| `ringtone` | Makes a 3–40 second MP3 clip with fades and sends it, then deletes the temp file. |
| `zip` | Packs up to 500 finished songs into a ZIP (stored, not compressed) and sends it. |
| `renameToTitle` *(private)* | Renames to `Title.ext`, `Title (1).ext`, … without overwriting another file; handles case-only changes on Windows. |
| `uniqueName`, `downloadFilename` *(private)* | Unique names inside a ZIP, and the file name offered to the browser. |

#### `FileConvertController`

Converting your own files.

| Method | What it does |
| --- | --- |
| `options` | What this browser may do: `local_files` (allowed?), `max_upload_mb`, `chunk_bytes` (8 MB), `max_files` (50), `extensions`. |
| `startUpload` | `{name, size}` → creates an upload folder and returns its `id`. Rejects files above `MAX_UPLOAD_MB`. |
| `uploadChunk` | Raw request body at `?offset=`. Answers `{received, done:false}`; after the last piece it inspects the file and answers `{done:true, file:{…}}`, or 422 when the file has no audio. 409 with `received` when the offset is wrong. 404 when the upload expired. |
| `cancelUpload` | Deletes an upload not yet used by a conversion. |
| `inspect` | Describes files on this computer (`{paths:[…]}`), only when `LocalFileAccess` allows it. |
| `convert` | Validates all items first (nothing is queued if one is wrong, duplicates rejected), then creates one `pending` download per item, marks uploads as used, and dispatches `ConvertLocalFileJob` for each. |
| `sourceFor` *(private)* | Turns an item into `[source, path, name]`: a finished, unused upload, or a real, readable local file (`realpath`). |

#### `FolderController`

| Method | What it does |
| --- | --- |
| `index` | Folder browser for the download folder: `path`, `parent`, `writable`, `folders` (max 500, hidden/system folders skipped), `places` (download folder, app storage, Home, Music, Videos, Downloads, Docker volume, drives or root). |
| `files` | Same listing plus video and audio files (max 1000), for "From this PC". Requires `LocalFileAccess`. |
| `store` | Creates a new folder; rejects names with `< > : " / \ \| ? *`. |

#### Other controllers

| Controller | Methods |
| --- | --- |
| `HistoryController` | `index`: completed downloads with search, `format`, `source` (`youtube` or `files`), sort (`completed_at`, `title`, `file_size`, `duration`), plus `can_open_folder`. `clear`: deletes all completed rows; with `keep_files=false` also deletes their files. |
| `DashboardController` | `stats`: total downloads, storage used, jobs waiting, failed jobs, failed downloads, free disk space, and the 5 most recent downloads (only those after `recent_after`, see Home Clear). |
| `PlaylistController` | `fetchInfo` (yt-dlp flat playlist), `store` (create or replace a playlist and its items, dispatch `ProcessPlaylistJob`), `index`, `show` (with items and downloads), `destroy`. |
| `QueueController` | `status` (pending, processing, failed, `worker_running`), `jobs` and `failedJobs` (rows with the related download), `retryAllFailed`, `retryJob`, `clearFailed` (with `download_ids`, removes only those downloads' failed jobs; without, all of them), `cancelJob`. Reads the download ID from a job payload with a regular expression instead of `unserialize()`. |
| `SettingsController` | `index`, `update` (when the download folder changes, also returns `previous_download_path` and `songs_in_previous_folder`), `moveSongs` (moves the songs of an earlier folder into the current one with `SongMover`), `checkDiskSpace`, `testDependencies`, `testYoutube`, `ytDlpVersion`, `updateYtDlp`, `setupChecks` (first-run checklist). |
| `LockController` | `status`, `unlock` (sets the cookie), `lock` (clears it). |

### 9.2 Jobs

#### `DownloadYoutubeAudioJob`

`timeout` 600 s, `tries` 3 and `backoff` 60/300/900 s, all read from config (`YOUTUBE_JOB_TIMEOUT`, `YOUTUBE_JOB_TRIES`). `deleteWhenMissingModels` drops the job when the download was deleted.

`handle()` steps:

1. Stop if cancelled. Mark as `processing`.
2. **Video info.** Run `extractVideoInfo` only when needed (placeholder title, or missing ID, duration or thumbnail) or when splitting by chapters. Titles the user set are kept. Empty tags are filled from yt-dlp and `TrackTitle::suggest`.
3. **Disk space.** Estimate the size (bitrate × duration; FLAC counted as 1000 kbps and WAV as 2304 kbps) and require `min_disk_space_mb` plus that estimate.
4. **Paths.** `<download folder>/<FileName::fromTitle(title)>.<format>`. If it exists: `Title (1).mp3`, `Title (2).mp3`, … The temp file is `storage/app/temp/download_<id>_<time>`.
5. **Download** with `YouTubeService::downloadAudio`, reporting progress.
6. **Convert** with `AudioConverterService::convert` when the extension differs from the target or edits are wanted. Otherwise the file is just moved. After edits, the real duration is read with ffprobe.
7. **Tags** with `MetadataService::tagFile`, then the **thumbnail** is downloaded and embedded (not for WAV). Tag failures are logged and do not fail the download.
8. **Lyrics** (when enabled and not splitting), written last so the cover-art rewrite does not drop them.
9. **Chapters** (`ChapterSplitService::split`), or the **waveform**.
10. Mark `completed`, then update the playlist counters.

Errors:

- **Cancelled.** Temp files and a half-written output are removed, and `status_text` becomes "Cancelled".
- **Any other error.** Files are cleaned up. If attempts remain, the row goes back to `pending` with "Attempt 1 of 3 failed, retrying...". The exception is re-thrown so the queue retries it.
- **`failed()`.** Called after the last attempt. Sets `failed` with "Download failed after N attempt(s): …".

#### `ConvertLocalFileJob`

`tries` 1 (a broken file fails the same way every time) and `timeout` 3600 s (long recordings). The steps are the same as above without yt-dlp:

1. `LocalFileService::inspect` the source. A missing upload says "The uploaded file is gone (uploads are kept for a day)".
2. Disk space check.
3. Output path in the download folder, never overwriting anything, including the original itself.
4. Convert, with `timeout` passed to ffmpeg.
5. Tags. Cover from the file's own art or a video frame, saved to `storage/app/covers/<id>.jpg` and embedded (not for WAV).
6. Lyrics.
7. Waveform. Mark `completed`.
8. An **upload** is deleted after success and `source_path` set to `null`. A **local original is never touched** here.

#### `ProcessPlaylistJob`

For every selected item without a download: when `Download::findExisting` finds the song already downloaded in the same format and quality, link the item to that download. Otherwise create a `pending` download with the placeholder title, link the item to it, and dispatch `DownloadYoutubeAudioJob` with a delay of `n × rate_limit_seconds`, where `n` counts only the queued songs. At the end it calls `Playlist::syncProgress()`, so reused songs count straight away and a playlist whose songs were all reused is `completed` at once.

#### `CleanupOldDownloadsJob` (daily)

1. Deletes files in `storage/app/temp` older than 24 hours.
2. `UploadStore::prune()` removes uploads with no activity for 24 hours. Uploads still waiting for a conversion (`pending` or `processing`) are kept.

Finished songs are never removed by this job.

### 9.3 Services

#### `YouTubeService` (yt-dlp)

| Function | What it does |
| --- | --- |
| `validateUrl` | Accepts `youtube.com`, `m.youtube.com`, `youtu.be`, `music.youtube.com` links. |
| `extractVideoId` | 11-character ID from `watch?v=`, `youtu.be/`, `/shorts/`, `/embed/`, `/v/`. |
| `extractPlaylistId` | Value of `list=`. |
| `cookieOptions` | `--cookies <file>` when a cookies file is set and exists, else `--cookies-from-browser <name>`, else nothing. |
| `runYtDlp` *(private)* | Runs yt-dlp with cookies. If the cookies cannot be read (browser open, DPAPI, keyring…), runs again without them. |
| `runCommand` *(private)* | `yt-dlp --retries 10 --fragment-retries 10 --extractor-retries 3 --socket-timeout 30 …` with `TEMP`/`TMP` set to `storage/app/temp` and UTF-8 output forced. |
| `failure` *(private)* | Adds advice to errors: rate limit or bot check → set cookies; 403 or format errors → update yt-dlp; timeouts → retried automatically. |
| `extractVideoInfo` | `--dump-json --no-playlist`; returns id, title, artist, track, `has_music_metadata`, uploader, album, year, genre, thumbnail, duration, chapters, url. |
| `chaptersFrom`, `yearFrom` *(private)* | Chapters of at least 1 second, only when there are 2 or more. Year from `release_year`, `release_date` or `upload_date`. |
| `search` | `ytsearch12:<query>` with `--flat-playlist`; keeps regular videos only (skips live and upcoming). |
| `extractPlaylistInfo` | `-J --flat-playlist`; returns id, title, videos (id, title, url, duration, position), total. |
| `downloadAudio` | `-f bestaudio/best -o <temp> --no-playlist --no-part --newline`; passes each progress line to `parseDownloadProgress`. |
| `parseDownloadProgress` | Reads `[download]  42.0% of ~  3.10MiB …` into `[42.0, "3.10MiB"]`. |
| `testAccess` | Runs `--simulate --print id` on a short public video to test the cookie settings. |
| `latestYtDlpVersion` | Newest release tag from GitHub, cached for 6 hours. |
| `updateYtDlp` | `yt-dlp -U`; for winget installs, `winget upgrade --id yt-dlp.yt-dlp`; for pip, returns the command to run. |
| `getYtDlpVersion`, `checkYtDlpInstalled`, `getDenoVersion` | Tool checks. |

#### `AudioConverterService` (ffmpeg)

| Function | What it does |
| --- | --- |
| `buildCommand` | Builds the conversion command (see 11.1). Trim with `-ss`/`-to` before `-i`; `-vn -ac 2`; filters for silence removal and loudness; format settings. |
| `convert` | Runs the command (timeout default 600 s), reports `[sizeKb, "HH:MM:SS.ss"]` progress, checks the output exists. |
| `parseProgress` | Matches `size=  1280kB time=00:00:10.00` (and `KiB` in ffmpeg 6+). |
| `ringtoneCommand` | Ringtone clip: `-ss`, `-t`, fades, 192k MP3, tags, ID3v2.3. |
| `getAudioInfo` | ffprobe: duration, bitrate, size, codec, sample rate, channels. |
| `getFfmpegVersion`, `validateFfmpegInstalled` | Tool checks. |

Format settings:

| Format | ffmpeg arguments |
| --- | --- |
| MP3 | `-ar 44100 -c:a libmp3lame -b:a 192k`, `256k` or `320k` |
| FLAC | `-ar 44100 -c:a flac -compression_level 8` |
| WAV | `-ar 48000 -c:a pcm_s24le` (24-bit, 48 kHz) |

Edit filters (joined into one `-af`):

| Option | Filter |
| --- | --- |
| Remove silence | `silenceremove=start_periods=1:start_threshold=-50dB:start_silence=0.2`. For audio up to 20 minutes it also removes trailing silence: `silence,areverse,silence,areverse`. |
| Even out volume | `loudnorm=I=-14:TP=-1.5:LRA=11` (−14 LUFS, peaks below −1.5 dB) |

#### `MetadataService` (tags)

| Function | What it does |
| --- | --- |
| `tagFile` | `ffmpeg -i file -map 0 -metadata title=… -codec copy` into a sibling `_temp` file, then replaces the original. `-map 0` keeps the cover. With `clearEmpty`, an empty value removes the tag. Maps `year` to the `date` tag. |
| `attachThumbnail` | Embeds an image as `attached_pic`, always re-encoded to JPEG (YouTube thumbnails are often WebP). |
| `downloadThumbnail` | Fetches the thumbnail over HTTP (30 s timeout). |
| `writeLyrics` | MP3: writes an ID3 `USLT` frame directly into the ID3v2.3 tag in UTF-16 (ffmpeg would write a `TXXX` frame that players ignore). FLAC: a `LYRICS` Vorbis comment. WAV: not supported. |
| `replaceFile` *(private)* | One overwriting `rename()`, tried up to 5 times with growing pauses. The song never disappears in between, even when a virus scanner or player holds the file briefly. |

#### `LocalFileService` (your own files)

| Function | What it does |
| --- | --- |
| `EXTENSIONS`, `VIDEO_EXTENSIONS`, `isMediaName` | 15 video and 18 audio extensions shown in the pickers. The real check is ffprobe. |
| `inspect` | ffprobe: name, size, duration, `has_video`, `has_cover`, codec, audio bitrate, number of audio tracks, plus suggested tags. Sets `error` when the file is missing, unreadable, or has no audio. |
| `suggestTags` | Uses the file's tags when present (cleaned, and written as "Artist - Song"), otherwise the file name (`my_song_name` becomes `my song name`). |
| `extractCover` | Saves the embedded artwork, or a video frame about a tenth of the way in, as a JPEG at most 640 px wide. |

#### `LyricsService` (LRCLIB)

1. The title is cleaned and the artist part is removed: "Artist - Song (Official Video)" becomes "Song".
2. With an artist and a duration: an exact `GET /api/get?track_name&artist_name&album_name&duration`.
3. Then `GET /api/search?track_name&artist_name`, then `?q=<main artist> <track>`. The main artist drops "ft.", "feat.", "x", "vs", "&" and ",".
4. A match must be within 4 seconds of the song's length.
5. The result is `found` (plain and timed lyrics), `instrumental` or `none`. When LRCLIB cannot be reached, the result is `null`, so `lyrics_status` stays `null` and the player tries again later.

HTTP timeout 10 s; up to 3 tries 1.5 s apart on connection errors and 429/502/503/504.

#### `ChapterSplitService`

Cuts a finished file into one file per chapter with `ffmpeg -ss <start> -to <end> -i <file> -map 0 -c copy` (no re-encode, no quality loss). Tracks go to a folder named after the video: `<Video title>/01 - <Chapter>.mp3`. Each track gets `title`, `artist`, `album` (the album or video title), `date`, `genre` and `track` (`n/total`) tags. The original download becomes track 1; one more completed download is created per further chapter. The whole recording is deleted afterwards. If one cut fails, every written track is removed.

#### `WaveformService`

Decodes the first audio stream to mono 16-bit PCM at 2000 Hz, splits it into 160 bars, takes the RMS of each bar, and normalises to the loudest bar with a curve (`100 × (level / max)^0.7`) so quiet parts stay visible. RMS is used instead of the peak because loud, heavily mastered music would otherwise draw a flat wall. Returns `null` on failure.

#### `DiskSpaceService`

`checkAvailableSpace` and `getTotalSpace` (MB, of the download folder); `hasEnoughSpace(required)` (free ≥ `min_disk_space_mb` + required); `estimateFileSize(seconds, kbps)` (= bitrate × seconds ÷ 8 × 1.1); `getUsedSpace`, `getUsagePercentage`, `formatBytes`.

### 9.4 Support classes

| Class | What it does |
| --- | --- |
| `TrackTitle` | `clean()` removes noise such as "(Official Music Video)", "[HD]", "\| Lyrics", "#shorts" but keeps "(Extended Mix)", "(feat. X)", "(Live)". `split()` turns "Artist - Song" into its two parts. `suggest()` builds the title and artist for a video. `songTitle()` drops the artist from the tag title. |
| `FileName` | `fromTitle()` keeps the title as the file name, replacing characters Windows forbids with spaces, removing trailing dots and spaces, cutting at 180 bytes, and refusing reserved names (`CON`, `NUL`, `COM1`…). |
| `UploadStore` | Chunked uploads in `storage/app/uploads/<uuid>/` with `source.<ext>` and `meta.json` (`name`, `size`, `file`, `used`). `create`, `append`, `exists`, `isComplete`, `meta`, `markUsed`, `path`, `delete`, `deleteFor`, `prune`. 8 MB pieces, 24-hour keep time. |
| `AppLock` | Optional password: `enabled`, `allows`, `check` (plain text or bcrypt/argon hash), `unlockCookie`, `lockCookie`. |
| `LocalFileAccess` | `allowed()`: the browser is on the same computer (`127.0.0.1` or `::1`) or the app has a password. |
| `SongMover` | `songsIn(folder)`: finished songs whose file is directly in that folder. `move(from, to)`: moves them, picks `Song (1).mp3`, … when a name is taken, updates `file_path`, and keeps a file that cannot be moved where it is. `samePath()` compares folders ignoring slashes, and letter case on Windows. |
| `FileExplorer` | `available()` (same computer and a desktop), `reveal()`: `explorer.exe /select,"…"` on Windows, `open -R` on macOS, `xdg-open` on Linux. |
| `ProcessOutput` | `error()`: the last lines of a failed command's output as valid UTF-8, at most 1000 characters. |
| `QueueWorkerHeartbeat` | `beat()` and `isAlive()`, see section 4. |

### 9.5 Models

| Model | Main members |
| --- | --- |
| `Download` | Constants `PLACEHOLDER_TITLE`, `FINAL_STATUSES`, `FORMATS`, `LOSSLESS_FORMATS`, `SOURCE_*`. Attributes `download_url`, `original_available`. `job()` returns the right job class for the source. `coverPath()`, `isConvertedFile()`, `isCancelled()` (fresh database check), `syncPlaylistProgress()`, `updateProgress()`. `findExisting()` (static): the latest completed, unedited, non-chapter download of the same video, format and quality whose file still exists; used by single, bulk and playlist downloads. Scopes `pending`, `processing`, `completed`, `failed`. The `deleted` hook removes the cover and a leftover upload. |
| `Playlist` | `items`, `selectedItems`, `syncProgress()` (recounts finished items, sets `completed` when all are done), `progress_percentage`. |
| `PlaylistItem` | `playlist`, `download`. |
| `Setting` | `get`, `set`, `downloadPath()`, `cleanTitles()`, `fetchLyrics()`, `absolutePath()`. Relative paths are anchored to the project root, because web requests run in `public/` and the worker in the project root. |

### 9.6 Console commands and schedule

| Command | What it does |
| --- | --- |
| `php artisan downloads:waveforms [--force]` | Creates waveforms for finished downloads that have none (or all, with `--force`). |
| `php artisan app:hash-password` | Asks for a password twice and prints `APP_PASSWORD='<hash>'` for `.env`. |
| Schedule (`routes/console.php`) | `CleanupOldDownloadsJob` daily. |

---

## 10. API reference

All endpoints are under `/api`. All except `/api/lock*` pass through `RequireAppPassword`.

| Method | Path | Controller@method |
| --- | --- | --- |
| GET | `/lock` | Lock@status |
| POST | `/lock/unlock` (10 per minute) | Lock@unlock |
| POST | `/lock` | Lock@lock |
| POST | `/downloads/fetch-info` | Download@fetchInfo |
| GET | `/downloads` | Download@index |
| POST | `/downloads` | Download@store |
| POST | `/downloads/bulk` | Download@bulk |
| GET | `/downloads/zip?ids=1,2,3` | Download@zip |
| GET | `/downloads/{id}` | Download@show |
| PATCH | `/downloads/{id}` | Download@update |
| DELETE | `/downloads/{id}` | Download@destroy |
| POST | `/downloads/{id}/retry` | Download@retry |
| POST | `/downloads/{id}/cancel` | Download@cancel |
| GET | `/downloads/{id}/waveform` | Download@waveform |
| GET | `/downloads/{id}/lyrics[?refresh=1]` | Download@lyrics |
| GET | `/downloads/{id}/ringtone?start&length&fade` | Download@ringtone |
| POST | `/downloads/{id}/reveal` | Download@reveal |
| GET | `/downloads/{id}/cover` | Download@cover |
| DELETE | `/downloads/{id}/original` | Download@destroyOriginal |
| GET | `/downloads/{id}/stream` (named `downloads.stream`) | Download@stream |
| GET | `/files/options` | FileConvert@options |
| GET | `/files/browse[?path]` | Folder@files |
| POST | `/files/inspect` | FileConvert@inspect |
| POST | `/files/uploads` | FileConvert@startUpload |
| POST | `/files/uploads/{upload}/chunk?offset=` | FileConvert@uploadChunk |
| DELETE | `/files/uploads/{upload}` | FileConvert@cancelUpload |
| POST | `/files/convert` | FileConvert@convert |
| GET | `/search?q=` | Download@search |
| GET | `/queue/status` | Queue@status |
| GET | `/queue/jobs` | Queue@jobs |
| GET | `/queue/failed` | Queue@failedJobs |
| POST | `/queue/retry-all` | Queue@retryAllFailed |
| POST | `/queue/clear-failed` | Queue@clearFailed |
| POST | `/queue/jobs/{id}/cancel` | Queue@cancelJob |
| POST | `/queue/failed/{id}/retry` | Queue@retryJob |
| POST | `/playlists/fetch-info` | Playlist@fetchInfo |
| GET | `/playlists` | Playlist@index |
| POST | `/playlists` | Playlist@store |
| GET | `/playlists/{id}` | Playlist@show |
| DELETE | `/playlists/{id}` | Playlist@destroy |
| GET | `/history` | History@index |
| DELETE | `/history[?keep_files=false]` | History@clear |
| GET | `/dashboard/stats[?recent_after]` | Dashboard@stats |
| GET | `/settings` | Settings@index |
| PUT | `/settings` | Settings@update |
| POST | `/settings/move-songs` | Settings@moveSongs |
| GET | `/settings/disk-space` | Settings@checkDiskSpace |
| GET | `/settings/test-dependencies` | Settings@testDependencies |
| GET | `/settings/test-youtube` | Settings@testYoutube |
| GET | `/settings/yt-dlp` | Settings@ytDlpVersion |
| POST | `/settings/yt-dlp/update` | Settings@updateYtDlp |
| GET | `/settings/setup-checks[?fresh=1]` | Settings@setupChecks |
| GET | `/settings/folders[?path]` | Folder@index |
| POST | `/settings/folders` | Folder@store |

Also `GET /up` (Laravel health check) and the catch-all `GET /{any}` that serves the SPA.

---

## 11. Flows with real examples

### 11.1 Download one YouTube video

**Example:** the user pastes `https://www.youtube.com/watch?v=5R3UPTNDBoM` (a song used in the project walkthrough), picks MP3 at 320 kbps and ticks "Even out volume".

```text
 Browser (SingleDownload.vue)           Web server                  Queue worker
 ─────────────────────────────          ──────────                  ────────────
 1. input changes; after 700 ms
    POST /api/downloads/fetch-info ───► DownloadController@fetchInfo
                                        YouTubeService::extractVideoInfo
                                          yt-dlp --dump-json ...
                                        TrackTitle::suggest
                        ◄────────────── { video:{...}, suggested:{title, artist} }
 2. preview card, tag fields filled
 3. "Download"
    POST /api/downloads ──────────────► DownloadController@store
                                        validate · findExisting (none)
                                        Download::create(status=pending)
                                        DownloadYoutubeAudioJob::dispatch ──► jobs table
                        ◄────────────── 201 { download:{id:41, status:"pending"} }
 4. every 1 s: GET /api/downloads/41                                   │
                                                                       ▼
                                                     DownloadYoutubeAudioJob::handle
                                                     yt-dlp → temp file
                                                     ffmpeg → final MP3
                                                     tags, cover, lyrics, waveform
                                                     status = completed
 5. status "completed" → stop polling,
    "Download finished." snackbar, Play / Save buttons
```

**Step 1: fetch info.**

```http
POST /api/downloads/fetch-info
{"youtube_url": "https://www.youtube.com/watch?v=5R3UPTNDBoM"}
```

The server runs (the cookie options appear only when they are set in Settings):

```text
yt-dlp --retries 10 --fragment-retries 10 --extractor-retries 3 --socket-timeout 30
       --dump-json --no-playlist --no-warnings https://www.youtube.com/watch?v=5R3UPTNDBoM
```

Suppose yt-dlp reports the title `Silver Bullet - NOBODY ELSE` from the channel `Silver Bullet`. `TrackTitle::suggest` finds no noise to clean. `split()` finds "Silver Bullet" as the artist. The answer pre-fills the form:

```json
{
  "success": true,
  "video": {
    "id": "5R3UPTNDBoM", "title": "Silver Bullet - NOBODY ELSE",
    "duration": 210, "chapters": [], "...": "..."
  },
  "suggested": { "title": "Silver Bullet - NOBODY ELSE", "artist": "Silver Bullet" }
}
```

**Step 2: create the download.**

```http
POST /api/downloads
{
  "youtube_url": "https://www.youtube.com/watch?v=5R3UPTNDBoM",
  "format": "mp3", "quality": "320",
  "title": "Silver Bullet - NOBODY ELSE", "artist": "Silver Bullet",
  "thumbnail_url": "https://i.ytimg.com/vi/5R3UPTNDBoM/maxresdefault.jpg",
  "trim_start": null, "trim_end": null,
  "remove_silence": false, "normalize_volume": true, "split_chapters": false
}
```

Because `normalize_volume` is on, the download counts as edited, so `findExisting` is skipped and a fresh file is always made. The answer is `201` with the new row (`id` 41, `status` `pending`).

**Step 3: the job runs on the worker.**

1. **Video info.** The form sent the title, ID and thumbnail but not the duration, so `needsVideoInfo()` is true and the job runs `yt-dlp --dump-json` once more. It stores the duration (210 s) and keeps the title and artist the user confirmed.
2. **Disk check.** 210 s × 320 kbps ÷ 8 × 1.1 ≈ 9 MB, so the download folder needs at least 500 + 9 = 509 MB free (with the default "keep free" of 500 MB).
3. **Paths.** `FileName::fromTitle` keeps the title, so the target is `<download folder>/Silver Bullet - NOBODY ELSE.mp3`. If that file exists already, the target becomes `Silver Bullet - NOBODY ELSE (1).mp3`.
4. **Download:**

   ```text
   yt-dlp --retries 10 --fragment-retries 10 --extractor-retries 3 --socket-timeout 30
          -f bestaudio/best -o storage/app/temp/download_41_1792828800
          --no-playlist --no-part --newline --no-warnings https://www.youtube.com/watch?v=5R3UPTNDBoM
   ```

   A line such as `[download]  42.0% of ~  3.10MiB at 1.2MiB/s ETA 00:02` becomes progress `5 + 42 × 0.6 = 30` and the text "Downloading... 42% (3.10MiB)".

5. **Convert.** yt-dlp saved for example `download_41_1792828800.webm` (Opus audio), so it is converted:

   ```text
   ffmpeg -y -i storage/app/temp/download_41_1792828800.webm -vn -ac 2
          -af loudnorm=I=-14:TP=-1.5:LRA=11
          -ar 44100 -c:a libmp3lame -b:a 320k "<download folder>/Silver Bullet - NOBODY ELSE.mp3"
   ```

   The ffmpeg line `size=4300kB time=00:01:45.00` is 105 s of 210 s = 50 %, which is overall progress `65 + 50 × 0.25 = 77` and the text "Converting to mp3... 50% (4.20 MB)".

6. **Tags.** `TrackTitle::songTitle` drops the artist from the title, so music players show "NOBODY ELSE" by "Silver Bullet":

   ```text
   ffmpeg -y -i "<file>.mp3" -map 0 -metadata title=NOBODY ELSE -metadata artist=Silver Bullet
          -codec copy -id3v2_version 3 "<file>_temp.mp3"     → renamed over the original
   ```

7. **Cover.** The thumbnail is downloaded, re-encoded to JPEG and embedded as `attached_pic`.
8. **Lyrics.** LRCLIB is asked for "NOBODY ELSE" by "Silver Bullet", 210 s. The walkthrough found none for this song, so `lyrics_status` becomes `none`.
9. **Waveform.** 160 values are stored. The row becomes `completed`, `progress` 100, with `file_path`, `file_size` and `completed_at` set.

**Step 4: play or save.** The row now has `download_url` = `/api/downloads/41/stream`. "Save" opens that URL; the browser gets `Silver Bullet - NOBODY ELSE.mp3` as `audio/mpeg`.

**Downloading the same link again** in MP3 320 without edits returns the existing row ("This video has already been downloaded") instead of a second file, as long as the file is still on disk.

### 11.2 How titles are cleaned (`TrackTitle`)

| Video title | `clean()` | `split()` | File name | Tag title |
| --- | --- | --- | --- | --- |
| `D-Sturb - LADY (Official Music Video)` | `D-Sturb - LADY` | `D-Sturb` / `LADY` | `D-Sturb - LADY.mp3` | `LADY` |
| `Song Name [HD] #shorts #music` | `Song Name` | none (artist = channel name) | `Song Name.mp3` | `Song Name` |
| `Artist - Track (Extended Mix)` | unchanged ("Extended Mix" is kept) | `Artist` / `Track (Extended Mix)` | `Artist - Track (Extended Mix).mp3` | `Track (Extended Mix)` |
| YouTube Music release with `artist` and `track` fields | n/a | n/a | `<artist> - <track>.mp3` | `<track>` |

Cleaning can be switched off under Settings → Titles & lyrics (`clean_titles`). The title is then kept as YouTube shows it.

`FileName::fromTitle` examples: `AC/DC - T.N.T.` → `AC DC - T.N.T` (slash replaced, trailing dot removed). `CON` → the fallback name, because Windows refuses it.

### 11.3 Search by song name

```text
SingleDownload: user types "nobody else silver bullet" and presses Enter
  → GET /api/search?q=nobody else silver bullet
      DownloadController@search
      Cache::remember('yt-search:' . sha1(lowercase query), 30 minutes)
         └─ YouTubeService::search → yt-dlp -J --flat-playlist "ytsearch12:nobody else silver bullet"
  ← { results: [ {id, title, url, channel, duration, views, thumbnail}, … up to 12 ] }
  → the user picks a result → fetchVideoInfo(result.url) → continues as 11.1
```

From Home, typing words instead of a link opens `/download?q=…`, which starts this search. A link opens `/download?url=…`, and a playlist link opens `/playlist?url=…`.

### 11.4 Several links at once

**Example:** the user pastes three links. One of them is a playlist link without a video, and one was already downloaded as MP3 320.

```http
POST /api/downloads/bulk
{ "urls": ["https://youtu.be/aVw71vt-U4s",
           "https://www.youtube.com/playlist?list=PL123",
           "https://www.youtube.com/watch?v=2RbKo0GmiCo"],
  "format": "mp3", "quality": "320" }
```

```json
{
  "success": true,
  "message": "Queued 1 download.",
  "queued": [ { "id": 52, "title": "Fetching...", "status": "pending" } ],
  "skipped": [
    { "url": "https://www.youtube.com/playlist?list=PL123",
      "reason": "Playlist link: import it on the Playlist page" },
    { "url": "https://www.youtube.com/watch?v=2RbKo0GmiCo",
      "reason": "Already downloaded", "download": { "...": "..." } }
  ]
}
```

Queued jobs are delayed `0, 2, 4, …` seconds (`rate_limit_seconds` = 2) to avoid YouTube rate limits. The placeholder title `Fetching...` is replaced by the real one when the job asks yt-dlp. Pasting more than one link into the single box switches the page to "Multiple links" automatically.

When nothing could be queued, the answer is `422` with the same `skipped` list, and the page shows why.

### 11.5 Import a playlist

```text
PlaylistImport.vue
 1. POST /api/playlists/fetch-info {url}
      → yt-dlp -J --flat-playlist <url>   (up to 300 s)
    ← { id, title, videos:[{id,title,url,duration,position}], total }
 2. the user unticks some videos, picks the format
 3. POST /api/playlists {url, title, items:[{id,title,url}], format, quality}
      PlaylistController@store
        ├─ more items than max_playlist_size (50)? → 422
        ├─ playlist key = list= value, or "url-" + sha1(url) for channel lists
        ├─ DB transaction: firstOrNew playlist, delete old items, create items
        └─ after commit: ProcessPlaylistJob::dispatch(playlist, format, quality)
 4. the browser goes to /queue
 5. worker: ProcessPlaylistJob, for each item:
            already downloaded (findExisting)? → link the item to that download
            otherwise → new Download + DownloadYoutubeAudioJob, delays 0 s, 2 s, 4 s, …
            then Playlist::syncProgress() counts the reused songs
 6. each finished, failed or cancelled download → Playlist::syncProgress()
            processed_videos = number of items whose download is final
            status = completed when processed_videos ≥ total_videos
```

**Example:** importing a 3-video playlist as MP3 320 creates downloads with delays of 0, 2 and 4 seconds. After two finish and one fails, `processed_videos` is 3 and the playlist is `completed`. Importing the same playlist again replaces its item list instead of failing on the unique key, and songs that are already downloaded as MP3 320 are reused, not downloaded again as `Title (1).mp3`. "Play" on an imported playlist plays its completed songs in playlist order.

### 11.6 Convert your own files: upload from the browser

**Example:** a 20 MB `holiday clip.mp4` is dropped on the Files tab.

```text
FileConvert.vue                                 Server
───────────────                                 ──────
GET /api/files/options ───────────────────────► { local_files, max_upload_mb:4096,
                                                  chunk_bytes:8388608, max_files:50, extensions }
POST /api/files/uploads
  {name:"holiday clip.mp4", size:20971520} ───► UploadStore::create
                                                  storage/app/uploads/<uuid>/meta.json
                                                  storage/app/uploads/<uuid>/source.mp4 (empty)
                                  ◄──────────── 201 { id:"<uuid>", chunk_bytes:8388608 }
POST …/<uuid>/chunk?offset=0         (8 MB) ──► append → { received:8388608,  done:false }
POST …/<uuid>/chunk?offset=8388608   (8 MB) ──► append → { received:16777216, done:false }
POST …/<uuid>/chunk?offset=16777216  (4 MB) ──► append → 20971520 = size
                                                LocalFileService::inspect (ffprobe)
                                  ◄──────────── { done:true, file:{ duration:95, has_video:true,
                                                  codec:"aac", bitrate:128, title:"holiday clip",… } }
the user edits tags or trim, presses "Convert 1 file to MP3"
POST /api/files/convert
  { items:[{upload_id:"<uuid>", title:"holiday clip"}],
    format:"mp3", quality:"320" } ────────────► validate all items, Download::create(source=upload)
                                                UploadStore::markUsed, ConvertLocalFileJob::dispatch
                                  ◄──────────── 201 { downloads:[{id:60, …}] }
every 1.5 s: GET /api/downloads?ids=60 ───────► progress (section 8.2)
```

How uploading stays robust:

- **One file at a time.** Uploads go in 8 MB pieces, so a single-threaded dev server stays responsive and a slow phone network does not time out.
- **Retries.** A piece that fails on a network error or a server error (5xx) is tried up to 4 times in total, waiting 1 s, 2 s and 3 s between tries.
- **Wrong offset.** If the server has a different amount of the file, it answers `409 { received }` and the browser continues from there. A piece sent again replaces everything after its offset.
- **Not convertible.** When the file has no audio, the server deletes the upload and answers `422`.
- **Leaving the page** stops unfinished uploads. Finished ones wait on the server for a day.

After the conversion succeeds, the uploaded copy is deleted (the user still has their own original), and `source_path` becomes `null`.

### 11.7 Convert your own files: from this PC

This option is shown only when `LocalFileAccess::allowed()`: the browser is on the same computer as the server, or the app has a password.

```text
"From this PC" → MediaFilePicker
   GET /api/files/browse?path=C:\Users\me\Videos
   ← { path, parent, folders:[…], files:[{name, path, size, kind:"video"}], places:[…] }
   (the last folder is remembered in localStorage "convert-folder")
the user ticks 2 files → POST /api/files/inspect {paths:[…]} → info per file
"Convert" → POST /api/files/convert {items:[{path:"C:\\Users\\me\\Videos\\talk.mkv"}, …]}
   sourceFor(): realpath, must be a readable file → Download(source=local, source_path=<path>)
   ConvertLocalFileJob reads the original in place (it is never changed)
when every conversion has finished:
   dialog "Delete the 2 original files?"  [Keep them] [Delete originals]
   only on "Delete originals": DELETE /api/downloads/{id}/original for each
```

The rule the user asked for is kept: originals are never deleted automatically. History also offers "Delete original file" for as long as `original_available` is true.

`ConvertLocalFileJob` example for a 1-hour `talk.mkv` to MP3 320 with "Remove silence":

```text
ffmpeg -y -i "C:\Users\me\Videos\talk.mkv" -vn -ac 2
       -af silenceremove=start_periods=1:start_threshold=-50dB:start_silence=0.2
       -ar 44100 -c:a libmp3lame -b:a 320k "<download folder>/talk.mp3"
```

The recording is longer than 20 minutes, so only leading silence is removed. Removing trailing silence reverses the audio in memory, which is too heavy for long files. The cover is a frame 6 minutes in (a tenth of the length), saved to `storage/app/covers/<id>.jpg`.

### 11.8 Split a video into tracks by its chapters

**Example:** a 45-minute DJ mix whose video has 12 chapters, with "Split into tracks" chosen.

1. `store` rejects the request when a trim is also set ("Splitting into chapters works on the whole video").
2. The job asks yt-dlp for the chapter list. The whole mix is downloaded, converted and tagged as usual. Lyrics are skipped.
3. `ChapterSplitService::split` creates the folder `<download folder>/<Mix title>/` (or `<Mix title> (1)` when it exists) and cuts:

   ```text
   ffmpeg -y -ss 0 -to 245.5 -i "<mix>.mp3" -map 0 -c copy
          -metadata title=Intro -metadata album=<Mix title> -metadata track=1/12 …
          "<Mix title>/01 - Intro.mp3"
   ```

4. Download 41 becomes track 1 (`chapter_index` 1). Downloads for tracks 2–12 are created as `completed`, each with its own waveform. The full-length file is deleted.

### 11.9 Edit tags of a finished song

```text
History → ⋮ (or right-click the song) → Edit tags → TagEditorDialog
  stops the player first if this song is playing (Windows locks open files)
  PATCH /api/downloads/41 {title:"Silver Bullet - Nobody Else", artist:"Silver Bullet", rename:true}
     MetadataService::tagFile(clearEmpty: true)   empty fields remove the tag
     writeLyrics again                            rewriting tags drops the lyrics frame
     renameToTitle → "Silver Bullet - Nobody Else.mp3"
        Windows treats a case-only change as the same name, so it is renamed in place
  ← { download: { …, file_path: ".../Silver Bullet - Nobody Else.mp3" } }
  409 "The file is in use…" when another app holds the file
```

### 11.10 Playing a song

```text
DownloadRow ▶ → player.js play(download, list)
  audio.src = download.download_url        GET /api/downloads/41/stream (range requests)
  navigator.mediaSession.metadata          OS media keys and lock-screen controls
PlayerBar watches player.track:
  no waveform stored? → GET /api/downloads/41/waveform   (generated once, then saved)
LyricsPanel opened:
  GET /api/downloads/41/lyrics
     lyrics_status null → LyricsService::lookup → saved → written into the file
  timed lines are highlighted 0.3 s ahead of the current time
"Search again" → ?refresh=1
```

When the audio element reports an error, `player.js` asks once more with `?retry=<time>` and resumes at the same position. This covers the empty 204 that a busy `php -S` server on Windows sometimes returns. If it fails again, a snackbar shows the error and, when playing a list, the next song starts.

The queue supports shuffle (keeping the current song first) and repeat off/all/one. Volume, shuffle and repeat are remembered in `localStorage`.

### 11.11 Make a ringtone

**Example:** a 30-second ringtone starting at 1:02 with fades.

1. `RingtoneDialog` suggests the loudest stretch of the waveform (usually the chorus), skipping the first 10 % and the last 5 %.
2. The user previews it in the player and presses Save:

   ```text
   GET /api/downloads/41/ringtone?start=62&length=30&fade=1
   ffmpeg -y -ss 62 -t 30 -i "<song>.mp3" -vn -ac 2 -ar 44100
          -af afade=t=in:d=0.5,afade=t=out:st=28:d=2
          -c:a libmp3lame -b:a 192k -metadata "title=NOBODY ELSE (Ringtone)"
          -metadata "artist=Silver Bullet" -id3v2_version 3 storage/app/temp/ringtone_<random>.mp3
   ```

3. The browser receives `Silver Bullet - NOBODY ELSE (Ringtone).mp3`. The temp file is deleted after sending.

The fade-out lasts `min(2, length ÷ 4)` seconds. The length is limited to 3–40 seconds (iPhone allows up to 40) and to the end of the song.

### 11.12 Download several songs as a ZIP

History → Select → tick songs → "Download ZIP" opens `GET /api/downloads/zip?ids=41,52,60`. The server keeps the selection order and skips files that no longer exist. Each song is stored without compression (audio is already compressed). The ZIP is sent as `AudioFetch - 3 songs 2026-10-24.zip` and deleted after sending. Two songs with the same file name become `Song.mp3` and `Song (2).mp3`. The PHP `zip` extension is required.

### 11.13 History, Home and clearing

- **History** lists completed downloads only, each on its own card. Filters are sent to the server: search, format, source (`youtube` / `files`) and sort. "Play all" loads up to 100 songs that match the current filters.
- **Song actions.** Each card shows Play, Save and More (⋮). On wider screens More (or a right-click on the card) opens a menu beside the button; on phones it opens a bottom sheet, where Save is listed too. Both come from one list (`sheetActions`), shown below.
- **Clear history** asks first, with a checkbox "Also delete the audio files from disk". Unticked sends `keep_files=true`: rows are removed and files are kept. Rows are deleted one by one, so the `deleted` hook also removes the cover images of converted files.
- **Home → Clear** only hides the recent list. The newest song's `completed_at` is stored in `localStorage` `home-cleared-at` and sent as `recent_after`, so Home shows only songs finished after that moment. Nothing is deleted. "Show recent again" removes the stored value.

The History song menu:

```text
Play  Save  More ─► ┌──────────────────────┐
                    │ Edit tags            │
                    │ Make ringtone        │
                    │ Show in folder       │  only from the same computer
                    ├──────────────────────┤
                    │ Delete original file │  red; converted local files only
                    │ Delete               │  red
                    └──────────────────────┘
```

The menu opens upwards when there is no room below and stays inside the window. Esc, a click outside, scrolling or resizing close it; arrow keys move between items.

### 11.14 Cancel, retry and failure

**Cancel while downloading.** `POST /api/downloads/41/cancel` sets `cancelled`. At the next whole percent, the job's progress callback calls `abortIfCancelled()`. It throws `DownloadCancelledException` from inside the output callback, which stops the running yt-dlp or ffmpeg process. The job then deletes the temp file and the half-written MP3.

**Automatic retry (YouTube only).** Suppose YouTube answers "HTTP Error 429" on the first try:

1. The error becomes "…YouTube is rate-limiting this computer. Wait a few minutes, or set a browser (or cookies.txt file) under Settings → YouTube access."
2. The row goes back to `pending` with "Attempt 1 of 3 failed, retrying...".
3. The queue retries after 60 s, then 300 s.
4. After the third failure, `failed()` sets `failed` and "Download failed after 3 attempt(s): …".

**Manual retry.** On the Queue page, or after a failure on the Download page, `POST /api/downloads/41/retry` resets progress and dispatches the right job for the source (`Download::job()`).

### 11.15 Queue page, worker banner and notifications

```text
App.vue, every 5 s: GET /api/queue/status
   { pending, processing, failed, worker_running }
   ├─ worker_running = false and pending > 0 → WorkerBanner "The queue worker is not running"
   └─ notifications.checkFinishedDownloads(pending)
         remembers active downloads; when one leaves the active list,
         fetches it and shows "Download finished" / "Download failed"
         through the service worker (required on Android)
QueueStatus.vue, every 1 s:
   GET /api/downloads?status=processing,pending&per_page=100   (Active tab)
   GET /api/downloads?status=failed,cancelled&per_page=100     (Failed tab)
   Failed tab → Clear: DELETE /api/downloads/{id} for each shown download,
      then POST /api/queue/clear-failed {download_ids:[…]}
      removes only those downloads' rows in failed_jobs
```

Clicking a notification opens `/history` (or `/queue` for failures) without reloading, so music keeps playing. The service worker posts a message to the open window, which calls `router.push`.

### 11.16 Settings and the setup checklist

- **Saving settings.** `PUT /api/settings` checks the download folder can be created and written to, and that a cookies file exists. Values are stored in the `settings` table; booleans as `'1'`/`'0'`, and empty values as `''` so they override `.env`. A new download folder can come with an offer to move the existing songs (section 11.19).
- **Setup checklist** (`GET /api/settings/setup-checks`) is shown on Home on first launch, and again whenever a new item fails. Tool versions are cached for 10 minutes because starting yt-dlp, FFmpeg and Deno takes seconds; "Recheck" passes `?fresh=1`.

| Check | Required | Suggested fix (Windows) |
| --- | --- | --- |
| yt-dlp | yes | `winget install yt-dlp.yt-dlp` |
| yt-dlp is up to date | no | Settings → System → Update yt-dlp |
| FFmpeg | yes | `winget install Gyan.FFmpeg` |
| Deno | no | `winget install DenoLand.Deno` |
| Queue worker | yes | `composer dev` (or `php artisan queue:work`) |
| Download folder is writable | yes | Pick another folder |
| Enough free disk space | no | Free up space or lower "Keep free" |
| PHP zip extension | no | Enable `extension=zip` in php.ini |

- **YouTube access test** (`GET /api/settings/test-youtube`) tries one request with the saved cookie settings.
- **yt-dlp update** (`POST /api/settings/yt-dlp/update`) runs `yt-dlp -U`. For winget installs it runs `winget upgrade`. For pip installs it explains the command to run.

### 11.17 Password lock (servers)

**Example:** `.env` has `APP_PASSWORD='$2y$12$…'` (made with `php artisan app:hash-password`).

```text
App.vue mounted → GET /api/lock → { enabled:true, unlocked:false } → LockScreen
any other /api call → 401 { locked:true } → ui.locked = true
LockScreen → POST /api/lock/unlock {password}       (at most 10 tries per minute)
   AppLock::check → Hash::check (or hash_equals for a plain-text password)
   ← { unlocked:true } + cookie yc_unlock = HMAC-SHA256("unlock|" + APP_PASSWORD, APP_KEY)
      (30 days, httpOnly, SameSite=Lax, Secure on https)
Settings → Access → Lock now → POST /api/lock → cookie removed
```

Changing `APP_PASSWORD` or `APP_KEY` changes the token, so every device is locked again.

### 11.18 Daily cleanup

The scheduler runs `CleanupOldDownloadsJob` once a day. Example: a phone started uploading a 2 GB video yesterday and the tab was closed. Its folder in `storage/app/uploads` has had no new piece for 24 hours, so it is removed. An upload already queued for conversion is kept even when it is older.

### 11.19 Change the download folder

Every finished song stores its full path in `file_path`. Changing the folder in Settings changes where **new** songs go; songs already saved stay where they are until the user moves them.

**Example:** 4 songs are in the default folder `D:\laragon\www\youtube_converter\storage\app\downloads`. The user picks `E:\Music` and saves.

```text
Settings.vue → PUT /api/settings {download_path:"E:\Music", …}
   SettingsController@update
     previous folder = Setting::downloadPath()   (before saving)
     checks E:\Music can be created and written to, saves the settings
     SongMover::songsIn(previous folder) → 4 finished songs still there
   ← { message, previous_download_path:"…\storage\app\downloads", songs_in_previous_folder:4 }

Dialog "Move your 4 songs too?"   [Leave them]  [Move]

Leave them → nothing changes
   "Show in folder" on an old song opens …\storage\app\downloads
   a new download is saved in, and opens, E:\Music

Move → POST /api/settings/move-songs {from:"…\storage\app\downloads"}
   SongMover::move(from, E:\Music), for each song directly in that folder:
     rename() into E:\Music; "LADY.mp3" taken there? → "LADY (1).mp3"
     update file_path   (History, the player and "Show in folder" follow)
     a file open in another program stays and is listed under "failed"
   ← { moved:4, failed:[], message:"Moved 4 songs." }
```

Songs in subfolders (for example chapter-split folders) are left alone. A song still downloading when the folder changes finishes in the old folder. Moving to a folder on another drive copies the file, so a large library takes a moment.

---

## 12. Frontend architecture

### 12.1 Structure

```text
app.js ── createApp(App) ── router (6 routes) ── mount #app ── register /sw.js
  │
  └─ App.vue (shell)
       ├─ side navigation (desktop) / bottom navigation (phone)
       ├─ WorkerBanner          worker down and jobs waiting
       ├─ <router-view>         the current page
       ├─ PlayerBar             always mounted, so music plays across pages
       │    ├─ WaveformSeek
       │    └─ LyricsPanel
       ├─ SnackbarHost          messages from ui.notify()
       ├─ ConfirmDialog         promise-based confirmDialog()
       └─ LockScreen            when ui.locked
```

### 12.2 Pages (routes)

| Route | View | What it shows |
| --- | --- | --- |
| `/` | `Dashboard.vue` | Link or search box, 4 stat tiles, setup checklist, recent downloads with Clear |
| `/download` | `SingleDownload.vue` | Tabs "Single link", "Multiple links", "Files" (`FileConvert.vue`); search results, preview, format, edits, tags, live progress. Query `?url=`, `?q=`, `?mode=files` |
| `/playlist` | `PlaylistImport.vue` | Load a playlist, pick videos, import; list of imported playlists with Play and Remove |
| `/queue` | `QueueStatus.vue` | Active and Failed tabs, cancel, retry, retry all, clear failed (`?tab=failed`) |
| `/history` | `DownloadHistory.vue` | Search, filters, sort, pages; song cards with play, save and an actions menu (edit tags, ringtone, show in folder, delete original, delete); select and ZIP; clear |
| `/settings` | `Settings.vue` | Storage (with the offer to move songs to a new folder), YouTube access, Titles & lyrics, Playlists, yt-dlp, Appearance, Notifications, Access, plus the setup checklist |

### 12.3 Shared modules

| Module | Role |
| --- | --- |
| `services/api.js` | One axios instance (`/api`, JSON) and grouped calls: `lockAPI`, `downloadAPI`, `filesAPI`, `searchAPI`, `queueAPI`, `playlistAPI`, `historyAPI`, `dashboardAPI`, `settingsAPI`. Its interceptor turns `401 locked` into `ui.locked`. |
| `ui.js` | Reactive `ui` state (snackbar, dialog, theme, accent, install prompt, lock). Helpers: `notify`, `confirmDialog`/`closeDialog`, `toggleTheme`, `setAccent` (darkens a colour until it is readable on white), `readStorage`/`writeStorage` (never throw), `errorMessage`, `formatBytes`, `formatDuration`, `parseTime` ("1:30" → 90), `formatRelative`, `extractYoutubeLinks`, `looksLikeYoutubeUrl`, `YOUTUBE_URL`, `installApp`. |
| `player.js` | One shared `Audio` element and reactive `player` state. `play`, `playAll`, `toggle`, `next`, `previous`, `seek`, `skip`, `setVolume`, `toggleMute`, `toggleShuffle`, `cycleRepeat`, `pause`, `stop`, `forget` (removes a deleted song from the queue), Media Session handlers, one retry on errors. |
| `notifications.js` | System notifications: `enableNotifications`, `disableNotifications`, `notificationsOn`, `checkFinishedDownloads`, `sendTestNotification`. |
| `public/sw.js` | Caches the app shell (`ytmp3-shell-v1`); pages network-first with the cached shell offline; `/build/*` cache-first (hashed names); never caches `/api/*`. Handles notification clicks. |

### 12.4 Components

| Component | Used by | Role |
| --- | --- | --- |
| `AppIcon` | everywhere | Renders an `@mdi/js` SVG path |
| `PageHeader`, `EmptyState`, `Disclosure`, `LinearProgress`, `SegmentedControl` (`large` variant for the Download tabs) | pages | Layout and controls |
| `DownloadRow` | Home, Download, Queue, History | Thumbnail, title, source chip ("YouTube" / "Local file"), meta line or live progress |
| `StatusChip` | rows | Status and current step |
| `FileConvert` | Download → Files | Upload queue, local files, per-file tags and trim, convert, progress, "delete originals?" |
| `MediaFilePicker` | FileConvert | Browse folders and media files on the host |
| `FolderPicker` | Settings | Choose or create the download folder |
| `TagEditorDialog` | History | Edit tags and rename |
| `RingtoneDialog` | History | Pick part and length, preview, save |
| `PlayerBar`, `WaveformSeek`, `LyricsPanel` | App | Player UI |
| `SetupChecklist` | Home, Settings | First-run checks with copyable fix commands |
| `WorkerBanner`, `SnackbarHost`, `ConfirmDialog`, `LockScreen` | App | App-wide UI |

### 12.5 Live updates by polling

There are no WebSockets. Pages poll the API while something is running:

| Where | Interval | Request |
| --- | --- | --- |
| App shell | 5 s | `/queue/status` |
| Download page (one download) | 1 s | `/downloads/{id}` |
| Files tab (its conversions) | 1.5 s | `/downloads?ids=…` |
| Queue page | 1 s | `/downloads?status=…` (twice) |

### 12.6 Data kept in the browser

| `localStorage` key | Holds |
| --- | --- |
| `theme`, `accent` | Light/dark and the accent colour (applied before first paint) |
| `normalize-volume` | Last choice of "Even out volume" |
| `player-volume`, `player-shuffle`, `player-repeat` | Player preferences |
| `home-cleared-at` | Home → Clear moment |
| `setup-seen`, `setup-dismissed` | Setup checklist visibility |
| `notifications` | Notifications wanted (`1`/`0`) |
| `convert-folder` | Last folder used in "From this PC" |

### 12.7 Look and themes

All colours are CSS variables in `resources/css/app.css`. `:root` holds the light theme, `[data-theme='dark']` the dark one, and `[data-accent]` swaps in the accent colour picked under Settings → Appearance.

| Variable | Light | Dark | Used for |
| --- | --- | --- | --- |
| `--md-bg` | `#e9edf5` | `#10131a` | Page background |
| `--md-surface` | `#ffffff` | `#181b22` | Cards and panels |
| `--md-glow` | accent at 9% | accent at 16% | Light falling from the top of the page |
| `--md-glow-2` | none | accent at 7% | Faint light in the bottom-right corner |
| `--md-song` | `--md-surface` | `--md-surface-2` (`#1f232b`) | Song cards |
| `--md-song-hover` | `--md-surface-2` | `--md-surface-3` (`#2a2f39`) | Song card under the pointer |

**Song cards.** `.song-list` (History and Home) lays the songs out as separate rounded cards with a small gap. In dark mode shadows barely show on dark panels, so the cards are one shade lighter than the panel instead. A selected card (History → Select) keeps its accent tint.

---

## 13. Configuration

### 13.1 `.env` and `config/youtube-converter.php`

| `.env` | Default | Meaning |
| --- | --- | --- |
| `YOUTUBE_DOWNLOAD_PATH` | `storage/app/downloads` | Where songs are saved (Settings can override) |
| `APP_PASSWORD` | empty | Password lock; plain text or a hash |
| `YTDLP_COOKIES_FROM_BROWSER` | empty | Browser to read YouTube cookies from |
| `YTDLP_COOKIES_FILE` | empty | Netscape `cookies.txt` |
| `CLEAN_TITLES` | `true` | Remove noise from titles |
| `FETCH_LYRICS` | `true` | Look up lyrics for new downloads |
| `MAX_UPLOAD_MB` | `4096` | Largest browser upload |
| `MAX_PLAYLIST_SIZE` | `50` | Most videos per playlist import |
| `RATE_LIMIT_SECONDS` | `2` | Gap between queued jobs |
| `MIN_DISK_SPACE_MB` | `500` | Space always kept free |
| `YOUTUBE_JOB_TIMEOUT` | `600` | Seconds per YouTube job |
| `YOUTUBE_JOB_TRIES` | `3` | Attempts per YouTube job |

Fixed values in the same file: `quality_options` `[192, 256, 320]`, `job_backoff` `[60, 300, 900]`. Tests also set `youtube-converter.upload_path`.

Laravel settings that matter: `DB_CONNECTION` (MySQL on the user's machine), `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`, `APP_KEY` (signs the unlock cookie).

### 13.2 Which value wins

```text
Setting::get('key')  →  row in the settings table?  yes → use it (also '' = empty)
                                                   no  → config('youtube-converter.…') → .env
```

The Settings page can change: `download_path`, `max_playlist_size`, `rate_limit_seconds`, `min_disk_space_mb`, `ytdlp_cookies_browser`, `ytdlp_cookies_file`, `clean_titles`, `fetch_lyrics`. `SettingsSeeder` stores the first three numeric defaults. It leaves the download path and cookies out on purpose, so they follow `.env` until changed.

---

## 14. Security design

| Risk | How it is handled |
| --- | --- |
| Strangers on a server using the app | `APP_PASSWORD` lock. The HMAC cookie is httpOnly and bound to the password and `APP_KEY`. The unlock endpoint allows 10 tries per minute. The middleware runs before model binding, so nothing leaks while locked. |
| Reading any file on the host through "From this PC" | `LocalFileAccess`: only from the same computer, or with a password. `realpath` and `is_file` checks. `source_path` is never sent to the browser. |
| Opening windows on the host | `FileExplorer::available()`: loopback only, and a desktop must exist. |
| Deleting the user's originals | Only after an explicit confirmation, only for `source=local` completed downloads, only the exact file that was converted. |
| Command injection through titles or paths | Every tool is started with an argument array. The Windows Explorer call uses `proc_open` with `bypass_shell`; Windows file names cannot contain quotes, so the path cannot break out. |
| Unsafe `unserialize()` of job payloads | `QueueController` reads the download ID with a regular expression. |
| Path tricks in uploads | Upload IDs must be UUIDs. The stored file name is `source.<sanitised extension>`. `deleteFor` only deletes folders directly inside the uploads root. |
| Invalid UTF-8 from Windows tools | `ProcessOutput::error` scrubs output before it reaches JSON or the database. |
| Filling the disk | `min_disk_space_mb` reserve plus a size estimate before each job, the `MAX_UPLOAD_MB` limit, and daily cleanup of temp files and uploads. |

---

## 15. Deployment

| Setup | How |
| --- | --- |
| Local, Laragon | Apache serves `http://youtube_converter.test`. Run a queue worker (`php artisan queue:work`). Database MySQL. |
| Local, development | `composer run dev` or `php artisan dev`: server, `queue:listen`, logs and Vite. |
| Docker | `docker compose up -d` → `http://localhost:8000`. The image has PHP 8.3, FFmpeg, the standalone yt-dlp build (can update itself) and Deno. Volumes: `./docker-data:/data` (SQLite database, app key, settings) and `./downloads:/downloads` (music). `entrypoint.sh` generates the app key once, migrates, seeds once, optimises, then runs the worker, scheduler and server. |
| Server | Same as Docker, or a normal Laravel host with a worker (`queue:work`) and cron (`schedule:run`). Set `APP_PASSWORD`. |

The Docker build has three stages: Node 22 builds the frontend (`npm ci`, `npm run build`), the Deno binary is copied from `denoland/deno`, and the final PHP image installs Composer dependencies without dev packages.

---

## 16. Testing

PHPUnit with three suites in `phpunit.xml`. Tests use SQLite in memory and a temporary download folder and uploads folder (`tests/Concerns/UsesTemporaryDownloadDirectory.php`). External tools are faked with `Process::fake()` and HTTP with `Http::fake()`.

| Suite | Files | Covers |
| --- | --- | --- |
| Unit | `TrackTitleTest`, `ProcessOutputTest`, `Services/AudioConverterServiceTest`, `Services/MetadataServiceTest` | Title cleaning, command building, progress parsing, tag commands |
| Feature, API | `DownloadApiTest`, `BulkAndTagEditApiTest`, `FileConvertTest`, `FolderPickerTest`, `HistoryApiTest`, `NewFeaturesApiTest`, `PlaylistApiTest`, `QueueApiTest`, `SettingsAndDashboardApiTest`, `ShowInFolderTest`, `ZipDownloadTest`, `AppLockTest` | Every endpoint |
| Feature, jobs and services | `Jobs/DownloadYoutubeAudioJobTest`, `Jobs/ConvertLocalFileJobTest`, `YouTubeServiceTest`, `DiskSpaceServiceTest`, `WaveformTest`, `LyricsVolumeRingtoneTest` | Job steps, retries, cancelling, cleanup, waveforms, lyrics, loudness, ringtones |
| Integration | `RealDownloadTest` | A real download with the real tools |

Run them with `composer test` (or `php artisan test`) using PHP 8.3. At the last full run on 2026-10-27, all 250 tests passed.

---

## 17. Notes found while reviewing the code

Items 1 to 5 were found while writing this document and fixed on 2026-10-27. Items 6 and 7 are still open.

1. **Fixed: missing space in a status text.** `DownloadYoutubeAudioJob` wrote `'Splitting into' . count($chapters)`, which showed as "Splitting into12 tracks...". It now shows "Splitting into 12 tracks...".
2. **Fixed: Clear history left cover images.** `HistoryController::clear` used a query delete, which skipped the model's `deleted` hook, so cover JPEGs of converted files stayed in `storage/app/covers/`. It now deletes the rows one by one (`lazyById(100)`), so the hook removes the covers. Test: `HistoryApiTest::test_clearing_history_removes_cover_images_of_converted_files`.
3. **Fixed: "Clear failed" cleared every failed job.** The Queue page deletes the failed downloads it shows, then called `clear-failed`, which ran `queue:flush` and emptied the whole `failed_jobs` table, including jobs that failed after the page loaded. The page now sends `download_ids`, and only those downloads' failed jobs are removed. Without `download_ids` the endpoint still clears everything. Test: `QueueApiTest::test_clear_failed_with_download_ids_removes_only_those_jobs`.
4. **Fixed: playlist imports downloaded existing songs again.** `findExisting` moved from `DownloadController` to `Download::findExisting`, and `ProcessPlaylistJob` now uses it like single and bulk downloads. Tests: `PlaylistApiTest::test_processing_job_reuses_songs_already_downloaded` and `test_playlist_completes_when_every_song_was_already_downloaded`.
5. **Fixed: unused code removed.** `AudioConverterService::extractAudio`, `MetadataService::extractMetadataFromYouTube` (and its test), `Download::markAsProcessing/markAsCompleted/markAsFailed`, and the `formats` entry in `config/youtube-converter.php` (the real list is `Download::FORMATS`). The `downloads.download_url` column is dropped by migration `2026_10_27_000001_drop_download_url_from_downloads_table`.
6. **Known environment issue.** Under `php artisan dev` on Windows, YouTube lookups made by the web process can fail: the bgutil POT provider's Deno times out after 15 seconds in that `php -S` process. Through Laragon Apache and the queue worker they work.
7. **`.env.example` still uses SQLite**, while the README's full setup uses MySQL.
