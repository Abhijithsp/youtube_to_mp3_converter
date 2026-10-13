<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    // Helper methods
    public static function get($key, $default = null)
    {
        $setting = static::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    /**
     * Directory where finished audio files are stored (Settings page value, else config)
     */
    public static function downloadPath(): string
    {
        return static::absolutePath(static::get('download_path') ?: config('youtube-converter.download_path'));
    }

    /**
     * Relative paths (e.g. YOUTUBE_DOWNLOAD_PATH=storage/app/downloads) would resolve
     * against the working directory, which is public/ for web requests but the
     * project root for the queue worker, so anchor them to the project root.
     */
    public static function absolutePath(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path);

        return $isAbsolute ? $path : base_path($path);
    }

    public static function set($key, $value)
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
}
