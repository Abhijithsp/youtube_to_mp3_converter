<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\YouTubeService;
use App\Services\AudioConverterService;
use App\Services\DiskSpaceService;
use App\Support\QueueWorkerHeartbeat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    private const TOOL_VERSIONS_CACHE = 'setup-tool-versions';

    public function __construct(
        protected YouTubeService $youtubeService,
        protected AudioConverterService $audioConverterService,
        protected DiskSpaceService $diskSpaceService
    ) {}

    /**
     * Keys editable on the Settings page with their fallback values
     */
    private function defaults(): array
    {
        return [
            'download_path' => config('youtube-converter.download_path'),
            'max_playlist_size' => (int) config('youtube-converter.max_playlist_size'),
            'rate_limit_seconds' => (int) config('youtube-converter.rate_limit_seconds'),
            'min_disk_space_mb' => (int) config('youtube-converter.min_disk_space_mb'),
            'ytdlp_cookies_browser' => (string) config('youtube-converter.cookies_from_browser'),
            'ytdlp_cookies_file' => (string) config('youtube-converter.cookies_file'),
            'clean_titles' => (bool) config('youtube-converter.clean_titles', true),
            'fetch_lyrics' => (bool) config('youtube-converter.fetch_lyrics', true),
        ];
    }

    public function index()
    {
        $stored = Setting::all()->pluck('value', 'key');

        $settings = [];
        foreach ($this->defaults() as $key => $default) {
            $value = $stored[$key] ?? $default;
            $settings[$key] = match (true) {
                is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOL),
                is_int($default) => (int) $value,
                default => (string) $value,
            };
        }

        return response()->json($settings + [
            'cookie_browsers' => YouTubeService::COOKIE_BROWSERS,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'download_path' => 'required|string',
            'max_playlist_size' => 'required|integer|min:1|max:1000',
            'rate_limit_seconds' => 'sometimes|integer|min:0|max:300',
            'min_disk_space_mb' => 'required|integer|min:100',
            'ytdlp_cookies_browser' => ['sometimes', 'nullable', Rule::in(YouTubeService::COOKIE_BROWSERS)],
            'ytdlp_cookies_file' => 'sometimes|nullable|string',
            'clean_titles' => 'sometimes|boolean',
            'fetch_lyrics' => 'sometimes|boolean',
        ]);

        $path = Setting::absolutePath(rtrim($data['download_path'], '/\\'));

        // The download job writes here, so reject paths it cannot create or write to
        if (!is_dir($path) && !@mkdir($path, 0755, true)) {
            throw ValidationException::withMessages([
                'download_path' => 'The download path does not exist and could not be created.',
            ]);
        }

        if (!is_writable($path)) {
            throw ValidationException::withMessages([
                'download_path' => 'The download path is not writable.',
            ]);
        }

        $data['download_path'] = $path;

        if (filled($data['ytdlp_cookies_file'] ?? null) && !is_file($data['ytdlp_cookies_file'])) {
            throw ValidationException::withMessages([
                'ytdlp_cookies_file' => 'The cookies file does not exist.',
            ]);
        }

        foreach ($data as $key => $value) {
            // Empty values are stored as '' so they override the .env defaults
            Setting::set($key, is_bool($value) ? ($value ? '1' : '0') : ($value ?? ''));
        }

        return response()->json(['message' => 'Settings updated successfully']);
    }

    /**
     * Try one YouTube request with the saved cookie settings
     */
    public function testYoutube()
    {
        return response()->json($this->youtubeService->testAccess());
    }

    /**
     * Installed and newest yt-dlp version
     */
    public function ytDlpVersion()
    {
        $installed = $this->youtubeService->getYtDlpVersion();
        $latest = $this->youtubeService->latestYtDlpVersion();

        return response()->json([
            'installed' => $installed,
            'latest' => $latest,
            'update_available' => $installed && $latest && version_compare($latest, $installed, '>'),
        ]);
    }

    /**
     * Update yt-dlp (self-update, or winget when installed that way)
     */
    public function updateYtDlp()
    {
        $result = $this->youtubeService->updateYtDlp();
        Cache::forget(self::TOOL_VERSIONS_CACHE);

        return response()->json($result);
    }

    /**
     * First-run checklist: everything the app needs to download and convert
     */
    public function setupChecks(Request $request)
    {
        // Starting yt-dlp, FFmpeg and Deno takes seconds, and `php artisan serve` handles one
        // request at a time, so versions are cached; "Recheck" passes ?fresh=1.
        if ($request->boolean('fresh')) {
            Cache::forget(self::TOOL_VERSIONS_CACHE);
        }

        ['yt_dlp' => $ytDlp, 'ffmpeg' => $ffmpeg, 'deno' => $deno] = Cache::remember(
            self::TOOL_VERSIONS_CACHE,
            now()->addMinutes(10),
            fn () => [
                'yt_dlp' => $this->youtubeService->getYtDlpVersion(),
                'ffmpeg' => $this->audioConverterService->getFfmpegVersion(),
                'deno' => $this->youtubeService->getDenoVersion(),
            ]
        );

        $latest = $ytDlp ? $this->youtubeService->latestYtDlpVersion() : null;
        $outdated = $ytDlp && $latest && version_compare($latest, $ytDlp, '>');
        $worker = QueueWorkerHeartbeat::isAlive();
        $path = Setting::downloadPath();
        $writable = (is_dir($path) || @mkdir($path, 0755, true)) && is_writable($path);
        $free = $this->diskSpaceService->checkAvailableSpace();
        $minFree = (int) Setting::get('min_disk_space_mb', config('youtube-converter.min_disk_space_mb', 500));
        $windows = PHP_OS_FAMILY === 'Windows';

        $checks = [
            [
                'key' => 'yt_dlp',
                'label' => 'yt-dlp',
                'required' => true,
                'ok' => $ytDlp !== null,
                'detail' => $ytDlp ? "Version {$ytDlp}" : 'Not found on PATH',
                'fix' => $windows ? 'winget install yt-dlp.yt-dlp' : 'pip install -U yt-dlp  (or your package manager)',
            ],
            [
                'key' => 'yt_dlp_current',
                'label' => 'yt-dlp is up to date',
                'required' => false,
                'ok' => !$outdated,
                'detail' => $outdated ? "{$latest} is available (you have {$ytDlp})" : 'Up to date',
                'fix' => 'Settings → System → Update yt-dlp',
            ],
            [
                'key' => 'ffmpeg',
                'label' => 'FFmpeg',
                'required' => true,
                'ok' => $ffmpeg !== null,
                'detail' => $ffmpeg ? "Version {$ffmpeg}" : 'Not found on PATH',
                'fix' => $windows ? 'winget install Gyan.FFmpeg' : 'sudo apt install ffmpeg  (or brew install ffmpeg)',
            ],
            [
                'key' => 'deno',
                'label' => 'Deno (JavaScript runtime for yt-dlp)',
                'required' => false,
                'ok' => $deno !== null,
                'detail' => $deno ? "Version {$deno}" : 'Not found: some YouTube videos may fail or have fewer formats',
                'fix' => $windows ? 'winget install DenoLand.Deno' : 'curl -fsSL https://deno.land/install.sh | sh',
            ],
            [
                'key' => 'queue_worker',
                'label' => 'Queue worker',
                'required' => true,
                'ok' => $worker,
                'detail' => $worker ? 'Running' : 'Not running: downloads will wait in the queue',
                'fix' => 'composer dev  (or php artisan queue:work)',
            ],
            [
                'key' => 'download_folder',
                'label' => 'Download folder is writable',
                'required' => true,
                'ok' => $writable,
                'detail' => $path,
                'fix' => 'Pick another folder under Settings → Storage',
            ],
            [
                'key' => 'disk_space',
                'label' => 'Enough free disk space',
                'required' => false,
                'ok' => $free >= $minFree,
                'detail' => "{$free} MB free (keeping {$minFree} MB)",
                'fix' => 'Free up space or lower "Keep free" under Settings → Storage',
            ],
            [
                'key' => 'zip',
                'label' => 'PHP zip extension',
                'required' => false,
                'ok' => class_exists(\ZipArchive::class),
                'detail' => class_exists(\ZipArchive::class)
                    ? 'Installed: songs can be saved together as a ZIP'
                    : 'Missing: "Download ZIP" in History will not work',
                'fix' => $windows ? 'Enable extension=zip in php.ini' : 'sudo apt install php-zip',
            ],
        ];

        return response()->json([
            'ready' => collect($checks)->where('required', true)->every('ok'),
            'checks' => $checks,
        ]);
    }

    public function checkDiskSpace()
    {
        return response()->json([
            'free_mb' => $this->diskSpaceService->checkAvailableSpace(),
            'total_mb' => $this->diskSpaceService->getTotalSpace(),
            'path' => Setting::downloadPath(),
        ]);
    }

    public function testDependencies()
    {
        $ytDlp = [
            'installed' => $this->youtubeService->checkYtDlpInstalled(),
            'version' => $this->youtubeService->getYtDlpVersion()
        ];
        
        $ffmpeg = [
            'installed' => $this->audioConverterService->validateFfmpegInstalled(),
            'version' => $this->audioConverterService->getFfmpegVersion()
        ];
        
        return response()->json([
            'yt_dlp' => $ytDlp,
            'ffmpeg' => $ffmpeg
        ]);
    }
}
