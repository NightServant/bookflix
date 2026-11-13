<?php

namespace Database\Factories;

use App\Enums\ModelKind;
use App\Models\Media;
use App\Models\MediaEmbedding;
use App\Models\ModelVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaEmbedding>
 */
class MediaEmbeddingFactory extends Factory
{
    /**
     * Define the model's default state: a random unit vector.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $vector = array_map(fn (): float => fake()->randomFloat(6, -1, 1), range(1, 384));
        $norm = sqrt(array_sum(array_map(fn (float $x): float => $x * $x, $vector))) ?: 1.0;

        return [
            'media_id' => Media::factory(),
            'model_version_id' => ModelVersion::factory()->state(['name' => 'embedding', 'kind' => ModelKind::Embedding]),
            'embedding' => array_map(fn (float $x): float => $x / $norm, $vector),
            'content_hash' => hash('sha256', fake()->sentence()),
        ];
    }
}
