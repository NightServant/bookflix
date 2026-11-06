<?php

namespace Database\Factories;

use App\Enums\ReasonType;
use App\Models\Media;
use App\Models\ModelVersion;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recommendation>
 */
class RecommendationFactory extends Factory
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
            'shelf' => 'for-you',
            'score' => fake()->randomFloat(4, 0, 1),
            'reason_type' => ReasonType::Trending,
            'reason_media_id' => null,
            'reason_payload' => ['features' => []],
            'model_version_id' => ModelVersion::factory(),
            'generated_at' => now(),
        ];
    }
}
