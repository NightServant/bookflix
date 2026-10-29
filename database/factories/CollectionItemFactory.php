<?php

namespace Database\Factories;

use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CollectionItem>
 */
class CollectionItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'collection_id' => Collection::factory(),
            'media_id' => Media::factory(),
            'position' => 0,
        ];
    }
}
