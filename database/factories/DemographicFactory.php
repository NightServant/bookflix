<?php

namespace Database\Factories;

use App\Models\Demographic;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Demographic>
 */
class DemographicFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => Str::headline($slug = fake()->unique()->slug(2)),
            'slug' => $slug,
        ];
    }
}
