<?php

namespace Database\Factories;

use App\Models\ExternalReview;
use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalReview>
 */
class ExternalReviewFactory extends Factory
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
            'external_id' => (string) fake()->unique()->numberBetween(1, 9_999_999),
            'author_name' => fake()->userName(),
            'rating' => fake()->numberBetween(0, 100),
            'excerpt' => fake()->sentence(12),
            'url' => 'https://anilist.co/review/'.fake()->numberBetween(1, 9_999_999),
            'published_at' => now()->subDays(fake()->numberBetween(1, 300)),
            'fetched_at' => now(),
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['hidden_at' => now()]);
    }
}
