<?php

namespace Database\Factories;

use App\Models\Trope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Trope>
 */
class TropeFactory extends Factory
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
