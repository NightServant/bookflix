<?php

namespace Database\Factories;

use App\Models\Genre;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPreference>
 */
class UserPreferenceFactory extends Factory
{
    /**
     * Define the model's default state. A genre by default; pass `preferable_type` (a morph alias) and `preferable_id` for another kind.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'preferable_type' => 'genre',
            'preferable_id' => Genre::factory(),
            'weight' => 1,
        ];
    }
}
