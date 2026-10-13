<?php

namespace Database\Factories;

use App\Models\Download;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Download>
 */
class DownloadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $videoId = Str::random(11);

        return [
            'youtube_url' => "https://www.youtube.com/watch?v={$videoId}",
            'youtube_id' => $videoId,
            'title' => fake()->sentence(3),
            'artist' => fake()->name(),
            'format' => 'mp3',
            'quality' => '320',
            'status' => 'pending',
            'progress' => 0,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn () => ['status' => 'processing', 'progress' => 40]);
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => 'failed', 'error_message' => 'Something broke']);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => 'cancelled']);
    }

    /**
     * A completed download; pass a path to point it at a real file.
     */
    public function completed(?string $filePath = null): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'progress' => 100,
            'file_path' => $filePath ?? storage_path('framework/testing/missing-' . Str::random(8) . '.mp3'),
            'file_size' => $filePath && file_exists($filePath) ? filesize($filePath) : 1024,
            'duration' => 180,
            'completed_at' => now(),
        ]);
    }
}
