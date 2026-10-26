<?php

namespace Database\Factories;

use App\Models\DeweyClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeweyClass>
 */
class DeweyClassFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => $code = fake()->unique()->numerify('###.##'),
            'name' => fake()->words(2, true),
            'description' => null,
            'parent_id' => null,
            'level' => 1,
            'path' => $code,
        ];
    }
}
