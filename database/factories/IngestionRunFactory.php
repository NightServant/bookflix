<?php

namespace Database\Factories;

use App\Enums\IngestionMode;
use App\Enums\IngestionStatus;
use App\Models\ExternalSource;
use App\Models\IngestionRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngestionRun>
 */
class IngestionRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_source_id' => ExternalSource::factory(),
            'mode' => IngestionMode::Incremental,
            'status' => IngestionStatus::Succeeded,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'counts' => ['fetched' => 0, 'created' => 0, 'updated' => 0, 'merged' => 0, 'skipped' => 0, 'failed' => 0],
        ];
    }
}
