<?php

namespace Database\Factories;

use App\Models\Playlist;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Playlist>
 */
class PlaylistFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'youtube_playlist_id' => 'PL' . Str::random(32),
            'title' => fake()->sentence(3),
            'total_videos' => 0,
            'processed_videos' => 0,
            'status' => 'pending',
        ];
    }
}
