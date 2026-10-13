<?php

namespace Database\Factories;

use App\Models\Playlist;
use App\Models\PlaylistItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlaylistItem>
 */
class PlaylistItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'playlist_id' => Playlist::factory(),
            'youtube_url' => 'https://www.youtube.com/watch?v=' . Str::random(11),
            'position' => 1,
            'selected' => true,
        ];
    }
}
