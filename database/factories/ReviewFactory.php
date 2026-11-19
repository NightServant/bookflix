<?php

namespace Database\Factories;

use App\Enums\ReviewState;
use App\Models\Media;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'media_id' => Media::factory(),
            'body' => fake()->paragraph(),
            'has_spoilers' => false,
            'status' => ReviewState::Published,
        ];
    }

    /**
     * Hidden by a moderator.
     */
    public function hidden(): static
    {
        return $this->state(fn () => ['status' => ReviewState::Hidden]);
    }

    /**
     * A review that gives the story away.
     */
    public function spoiler(): static
    {
        return $this->state(fn () => ['has_spoilers' => true]);
    }
}
