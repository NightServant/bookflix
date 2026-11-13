<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\MediaSummary;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaSummary>
 */
class MediaSummaryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'media_id' => Media::factory(),
            'summary' => fake()->sentences(2, true),
            'source_hash' => hash('sha256', fake()->paragraph()),
            'method' => 'lsa',
            'generated_at' => now(),
        ];
    }
}
