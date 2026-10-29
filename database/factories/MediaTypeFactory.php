<?php

namespace Database\Factories;

use App\Enums\MediaFamily;
use App\Enums\ReadingMode;
use App\Models\MediaType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaType>
 */
class MediaTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => $slug = fake()->unique()->slug(2),
            'name' => Str::headline($slug),
            'family' => MediaFamily::Prose,
            'reading_mode' => ReadingMode::Reflowable,
        ];
    }
}
