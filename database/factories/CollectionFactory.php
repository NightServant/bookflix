<?php

namespace Database\Factories;

use App\Models\Collection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Collection>
 */
class CollectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'slug' => fn (array $attributes): string => Str::slug($attributes['name']),
            'visibility' => 'private',
            'is_editorial' => false,
        ];
    }

    /**
     * A public collection.
     */
    public function public(): static
    {
        return $this->state(fn () => ['visibility' => 'public']);
    }

    /**
     * A staff pick.
     */
    public function editorial(): static
    {
        return $this->state(fn () => ['is_editorial' => true]);
    }
}
