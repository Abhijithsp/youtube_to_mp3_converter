<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Download extends Model
{
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
        'download_url',
        'completed_at',
    ];

    protected $casts = [
        'quality' => 'string',
        'file_size' => 'integer',
        'original_file_size' => 'integer',
        'duration' => 'integer',
        'progress' => 'integer',
        'conversion_progress' => 'integer',
        'completed_at' => 'datetime',
    ];

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
}
