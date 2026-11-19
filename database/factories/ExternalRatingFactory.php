<?php

namespace Database\Factories;

use App\Models\ExternalRating;
use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalRating>
 */
class ExternalRatingFactory extends Factory
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
            'source' => 'anilist',
            'score' => fake()->numberBetween(0, 100),
            'votes' => fake()->numberBetween(1, 5000),
            'url' => null,
            'fetched_at' => now(),
        ];
    }
}
