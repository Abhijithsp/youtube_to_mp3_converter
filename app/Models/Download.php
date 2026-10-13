<?php

namespace App\Models;

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

    protected $fillable = [
        'user_id',
        'youtube_url',
        'youtube_id',
        'title',
        'artist',
        'album',
        'thumbnail_url',
        'format',
        'quality',
        'file_path',
        'file_size',
        'original_file_size',
        'duration',
        'status',
        'status_text',
        'error_message',
        'progress',
        'conversion_progress',
        'completed_at',
    ];

    protected $appends = ['download_url'];

    protected $casts = [
        'quality' => 'string',
        'file_size' => 'integer',
        'original_file_size' => 'integer',
        'duration' => 'integer',
        'progress' => 'integer',
        'conversion_progress' => 'integer',
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
    public function markAsProcessing()
    {
        $this->update(['status' => 'processing', 'progress' => 0]);
    }

    public function markAsCompleted($filePath, $fileSize)
    {
        $this->update([
            'status' => 'completed',
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'progress' => 100,
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed($errorMessage)
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
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
