<?php

namespace Database\Factories;

use App\Enums\RecommendationEventType;
use App\Models\Recommendation;
use App\Models\RecommendationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecommendationEvent>
 */
class RecommendationEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recommendation_id' => Recommendation::factory(),
            'event' => RecommendationEventType::Impression,
            'occurred_at' => now(),
        ];
    }
}
