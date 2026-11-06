<?php

namespace Database\Factories;

use App\Models\MlJobRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MlJobRun>
 */
class MlJobRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'train-lsa',
            'status' => 'queued',
            'params' => [],
            'started_at' => now(),
        ];
    }
}
