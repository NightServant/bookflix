<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\ReadingProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReadingProgress>
 */
class ReadingProgressFactory extends Factory
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
            'percent' => fake()->randomFloat(2, 1, 99),
            'last_read_at' => now(),
        ];
    }
}
