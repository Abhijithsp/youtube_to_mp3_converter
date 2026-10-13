<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Download Path
    |--------------------------------------------------------------------------
    |
    | The directory where downloaded audio files will be stored.
    |
    */
    'download_path' => env('YOUTUBE_DOWNLOAD_PATH', storage_path('app' . DIRECTORY_SEPARATOR . 'downloads')),

    /*
    |--------------------------------------------------------------------------
    | YouTube Cookies
    |--------------------------------------------------------------------------
    |
    | YouTube sometimes answers "Sign in to confirm you're not a bot". Passing the
    | cookies of a browser where you are signed in to YouTube avoids this. Either
    | name a browser (firefox, chrome, edge, brave, ...) or point to an exported
    | Netscape-format cookies.txt file. Both can also be set on the Settings page.
    |
    | On Windows, Chromium-based browsers lock their cookie database while open,
    | so Firefox or a cookies.txt file is the most reliable choice. If cookies
    | cannot be read, downloads automatically continue without them.
    |
    */
    'cookies_from_browser' => env('YTDLP_COOKIES_FROM_BROWSER'),

    'cookies_file' => env('YTDLP_COOKIES_FILE'),

    /*
    |--------------------------------------------------------------------------
    | Clean Titles
    |--------------------------------------------------------------------------
    |
    | Remove noise such as "(Official Music Video)", "[HD]" or "| Lyrics" from
    | video titles, which are also used as file names. Can be changed on the
    | Settings page.
    |
    */
    'clean_titles' => env('CLEAN_TITLES', true),

    /*
    |--------------------------------------------------------------------------
    | Max Playlist Size
    |--------------------------------------------------------------------------
    |
    | Maximum number of videos allowed in a playlist import.
    |
    */
    'max_playlist_size' => env('MAX_PLAYLIST_SIZE', 50),

    /*
    |--------------------------------------------------------------------------
    | Rate Limit Seconds
    |--------------------------------------------------------------------------
    |
    | Delay in seconds between consecutive download jobs.
    |
    */
    'rate_limit_seconds' => env('RATE_LIMIT_SECONDS', 2),

    /*
    |--------------------------------------------------------------------------
    | Minimum Disk Space (MB)
    |--------------------------------------------------------------------------
    |
    | Minimum required disk space in megabytes before allowing downloads.
    |
    */
    'min_disk_space_mb' => env('MIN_DISK_SPACE_MB', 500),

    /*
    |--------------------------------------------------------------------------
    | Supported Formats
    |--------------------------------------------------------------------------
    |
    | Audio formats supported by the converter.
    |
    */
    'formats' => ['mp3', 'flac'],

    /*
    |--------------------------------------------------------------------------
    | Quality Options
    |--------------------------------------------------------------------------
    |
    | Available bitrate options for audio conversion (in kbps).
    |
    */
    'quality_options' => [192, 256, 320],

    /*
    |--------------------------------------------------------------------------
    | Job Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time in seconds for a download job to complete.
    |
    */
    'job_timeout' => env('YOUTUBE_JOB_TIMEOUT', 600),

    /*
    |--------------------------------------------------------------------------
    | Job Retry Attempts
    |--------------------------------------------------------------------------
    |
    | Number of times to retry a failed download job.
    |
    */
    'job_tries' => env('YOUTUBE_JOB_TRIES', 3),

    /*
    |--------------------------------------------------------------------------
    | Job Backoff (seconds)
    |--------------------------------------------------------------------------
    |
    | Exponential backoff delays for job retries.
    |
    */
    'job_backoff' => [60, 300, 900],
];
