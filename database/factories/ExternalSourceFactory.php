<?php

namespace Database\Factories;

use App\Enums\CoverPolicy;
use App\Models\ExternalSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalSource>
 */
class ExternalSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => fake()->company(),
            'base_url' => fake()->url(),
            'enabled' => true,
            'attribution' => fake()->sentence(),
            'cover_policy' => CoverPolicy::Hotlink,
            'display_policy' => [],
        ];
    }
}
