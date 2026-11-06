<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\MediaSimilarity;
use App\Models\ModelVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaSimilarity>
 */
class MediaSimilarityFactory extends Factory
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
            'similar_media_id' => Media::factory(),
            'method' => 'lsa',
            'score' => fake()->randomFloat(4, 0, 1),
            'model_version_id' => ModelVersion::factory(),
        ];
    }
}
