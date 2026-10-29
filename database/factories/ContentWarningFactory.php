<?php

namespace Database\Factories;

use App\Models\ContentWarning;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ContentWarning>
 */
class ContentWarningFactory extends Factory
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
