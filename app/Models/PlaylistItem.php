<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlaylistItem extends Model
{
    /** @use HasFactory<\Database\Factories\PlaylistItemFactory> */
    use HasFactory;

    protected $fillable = [
        'playlist_id',
        'download_id',
        'youtube_url',
        'position',
        'selected',
    ];

    protected $casts = [
        'position' => 'integer',
        'selected' => 'boolean',
    ];

    // Relationships
    public function playlist()
    {
        return $this->belongsTo(Playlist::class);
    }

    public function download()
    {
        return $this->belongsTo(Download::class);
    }
}
