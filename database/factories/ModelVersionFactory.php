<?php

namespace Database\Factories;

use App\Enums\ModelKind;
use App\Enums\ModelStatus;
use App\Models\ModelVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModelVersion>
 */
class ModelVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'version' => 'v'.fake()->unique()->numberBetween(1, 100000),
            'kind' => ModelKind::Lsa,
            'status' => ModelStatus::Candidate,
            'trained_at' => now(),
            'parameters' => [],
            'metrics' => [],
        ];
    }

    /**
     * Indicate that the version is the active one of its name.
     */
    public function active(): static
    {
        return $this->state(['status' => ModelStatus::Active]);
    }
}
