<?php

namespace Database\Factories;

use App\Models\ExternalSource;
use App\Models\Media;
use App\Models\MediaExternalId;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaExternalId>
 */
class MediaExternalIdFactory extends Factory
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
            'external_source_id' => ExternalSource::factory(),
            'external_id' => fake()->unique()->bothify('OL#######W'),
            'payload_hash' => hash('sha256', fake()->uuid()),
            'imported_at' => now(),
            'last_synced_at' => now(),
        ];
    }
}
