<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\ReadingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReadingSession>
 */
class ReadingSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $seconds = fake()->numberBetween(300, 3600);
        $from = fake()->randomFloat(2, 0, 80);

        return [
            'user_id' => User::factory(),
            'media_id' => Media::factory(),
            'started_at' => now()->subSeconds($seconds),
            'ended_at' => now(),
            'seconds' => $seconds,
            'from_percent' => $from,
            'to_percent' => $from + fake()->randomFloat(2, 1, 20),
        ];
    }
}
