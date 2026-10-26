<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\MediaType;
use Database\Seeders\MediaTypeSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'media_type_id' => MediaType::factory(),
            'title' => fake()->unique()->sentence(3),
            'description' => fake()->paragraph(),
            'publication_year' => fake()->numberBetween(1950, 2025),
            'language' => 'en',
        ];
    }

    /**
     * Indicate that the media is a manga.
     */
    public function manga(): static
    {
        return $this->ofType('manga');
    }

    /**
     * Indicate that the media is a graphic novel.
     */
    public function graphicNovel(): static
    {
        return $this->ofType('graphic-novel');
    }

    /**
     * Indicate that the media is a novel.
     */
    public function novel(): static
    {
        return $this->ofType('novel');
    }

    /**
     * Use the seeded media type with this slug, creating it when the seeder has not run.
     */
    protected function ofType(string $slug): static
    {
        return $this->state(fn () => [
            'media_type_id' => MediaType::firstOrCreate(['slug' => $slug], MediaTypeSeeder::TYPES[$slug])->id,
        ]);
    }
}
