<?php

namespace Database\Factories;

use App\Enums\DuplicateStatus;
use App\Models\DuplicateCandidate;
use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DuplicateCandidate>
 */
class DuplicateCandidateFactory extends Factory
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
            'duplicate_media_id' => Media::factory(),
            'score' => fake()->randomFloat(2, 0.75, 0.89),
            'status' => DuplicateStatus::Pending,
        ];
    }
}
