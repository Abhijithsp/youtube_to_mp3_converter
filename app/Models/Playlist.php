<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Playlist extends Model
{
    /** @use HasFactory<\Database\Factories\PlaylistFactory> */
    use HasFactory;

    protected $fillable = [
        'youtube_playlist_id',
        'title',
        'total_videos',
        'processed_videos',
        'status',
    ];

    protected $casts = [
        'total_videos' => 'integer',
        'processed_videos' => 'integer',
    ];

    // Relationships
    public function items()
    {
        return $this->hasMany(PlaylistItem::class);
    }

    public function selectedItems()
    {
        return $this->hasMany(PlaylistItem::class)->where('selected', true);
    }

    // Helper methods
    /**
     * Recount items whose download reached a final state. Counting (instead of
     * incrementing) keeps the number right when a download is retried or several
     * queue workers finish items of the same playlist at once.
     */
    public function syncProgress(): void
    {
        $processed = $this->items()
            ->whereHas('download', fn ($query) => $query->whereIn('status', Download::FINAL_STATUSES))
            ->count();

        $attributes = ['processed_videos' => $processed];

        if ($this->total_videos > 0 && $processed >= $this->total_videos) {
            $attributes['status'] = 'completed';
        }

        $this->update($attributes);
    }

    public function getProgressPercentageAttribute()
    {
        if ($this->total_videos == 0) {
            return 0;
        }
        
        return round(($this->processed_videos / $this->total_videos) * 100);
    }
}
