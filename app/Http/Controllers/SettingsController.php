<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\YouTubeService;
use App\Services\AudioConverterService;
use App\Services\DiskSpaceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
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
        ];
    }

    public function index()
    {
        $stored = Setting::all()->pluck('value', 'key');

        $settings = [];
        foreach ($this->defaults() as $key => $default) {
            $value = $stored[$key] ?? $default;
            $settings[$key] = is_int($default) ? (int) $value : (string) $value;
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
            Setting::set($key, $value ?? '');
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
