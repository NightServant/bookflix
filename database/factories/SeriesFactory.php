<?php

namespace Database\Factories;

use App\Enums\PublicationStatus;
use App\Models\Series;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Series>
 */
class SeriesFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => Str::headline($slug = fake()->unique()->slug(3)),
            'slug' => $slug,
            'description' => fake()->sentence(),
            'status' => PublicationStatus::Ongoing,
        ];
    }
}
