<?php

namespace App\Models;

use App\Jobs\ConvertLocalFileJob;
use App\Jobs\DownloadYoutubeAudioJob;
use App\Support\UploadStore;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Download extends Model
{
    /** @use HasFactory<\Database\Factories\DownloadFactory> */
    use HasFactory;

    /**
     * Placeholder title used until yt-dlp metadata has been fetched.
     */
    public const PLACEHOLDER_TITLE = 'Fetching...';

    /**
     * Statuses after which no job will touch the download again.
     */
    public const FINAL_STATUSES = ['completed', 'failed', 'cancelled'];

    /**
     * Output formats; bitrate only applies to the lossy ones
     */
    public const FORMATS = ['mp3', 'flac', 'wav'];

    public const LOSSLESS_FORMATS = ['flac', 'wav'];

    /**
     * Where the audio came from: a YouTube video, a file sent from the browser,
     * or a file on the computer running the app
     */
    public const SOURCE_YOUTUBE = 'youtube';

    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_LOCAL = 'local';

    protected $fillable = [
        'user_id',
        'source',
        'source_path',
        'source_name',
        'youtube_url',
        'youtube_id',
        'title',
        'artist',
        'album',
        'year',
        'genre',
        'thumbnail_url',
        'format',
        'quality',
        'trim_start',
        'trim_end',
        'remove_silence',
        'normalize_volume',
        'split_chapters',
        'chapter_index',
        'file_path',
        'file_size',
        'original_file_size',
        'duration',
        'waveform',
        'lyrics',
        'synced_lyrics',
        'lyrics_status',
        'status',
        'status_text',
        'error_message',
        'progress',
        'conversion_progress',
        'completed_at',
    ];

    protected $attributes = ['source' => self::SOURCE_YOUTUBE];

    protected $appends = ['download_url', 'original_available'];

    // Paths on the computer stay on the server; the UI only needs the file name
    protected $hidden = ['source_path'];

    protected $casts = [
        'quality' => 'string',
        'file_size' => 'integer',
        'original_file_size' => 'integer',
        'duration' => 'integer',
        'waveform' => 'array',
        'progress' => 'integer',
        'conversion_progress' => 'integer',
        'trim_start' => 'integer',
        'trim_end' => 'integer',
        'remove_silence' => 'boolean',
        'normalize_volume' => 'boolean',
        'split_chapters' => 'boolean',
        'chapter_index' => 'integer',
        'year' => 'integer',
        'completed_at' => 'datetime',
    ];

    /**
     * Link to the stream endpoint, available once the file exists. Computed rather than
     * read from the column so it always matches the current route and host.
     */
    protected function downloadUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->status === 'completed' && $this->file_path && $this->exists
                ? route('downloads.stream', $this)
                : null
        );
    }

    /**
     * A converted file from this computer that is still there, so the UI can offer to delete it
     */
    protected function originalAvailable(): Attribute
    {
        return Attribute::get(
            fn () => $this->source === self::SOURCE_LOCAL
                && $this->source_path
                && $this->source_path !== $this->file_path
                && is_file($this->source_path)
        );
    }

    protected static function booted(): void
    {
        // Cover art taken from a converted file, and an upload still waiting for its
        // conversion, live in the app's storage. Files on this computer are left alone.
        static::deleted(function (Download $download) {
            @unlink($download->coverPath());

            if ($download->source === self::SOURCE_UPLOAD) {
                app(UploadStore::class)->deleteFor($download->source_path);
            }
        });
    }

    public function isConvertedFile(): bool
    {
        return in_array($this->source, [self::SOURCE_UPLOAD, self::SOURCE_LOCAL], true);
    }

    /**
     * The queue job that makes this download's file
     */
    public function job(): DownloadYoutubeAudioJob|ConvertLocalFileJob
    {
        return $this->isConvertedFile() ? new ConvertLocalFileJob($this) : new DownloadYoutubeAudioJob($this);
    }

    /**
     * Cover image taken from a converted file (embedded art or a video frame)
     */
    public function coverPath(): string
    {
        return storage_path('app' . DIRECTORY_SEPARATOR . 'covers' . DIRECTORY_SEPARATOR . $this->getKey() . '.jpg');
    }

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function playlistItem()
    {
        return $this->hasOne(PlaylistItem::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    // Helper methods
    /**
     * An earlier, untrimmed download of the same video in the same format/quality
     * whose file still exists
     */
    public static function findExisting(string $videoId, string $format, string $quality): ?self
    {
        return static::where('youtube_id', $videoId)
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

    public function updateProgress($progress)
    {
        $this->update(['progress' => min(100, max(0, $progress))]);
    }

    /**
     * Fresh check against the database (the user may cancel or delete while a job runs)
     */
    public function isCancelled(): bool
    {
        $status = static::whereKey($this->getKey())->value('status');

        return $status === null || $status === 'cancelled';
    }

    /**
     * Update the parent playlist's counters, if this download belongs to one
     */
    public function syncPlaylistProgress(): void
    {
        $this->playlistItem()->first()?->playlist?->syncProgress();
    }
}
