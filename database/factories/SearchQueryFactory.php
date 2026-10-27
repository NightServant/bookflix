<?php

namespace Database\Factories;

use App\Models\SearchQuery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SearchQuery>
 */
class SearchQueryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $query = fake()->words(2, true);

        return [
            'user_id' => null,
            'session_hash' => hash('sha256', fake()->uuid()),
            'query' => $query,
            'normalized' => $query,
            'filters' => [],
            'results_count' => fake()->numberBetween(0, 40),
        ];
    }
}
