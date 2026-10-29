<?php

namespace Database\Factories;

use App\Enums\ReadingLength;
use App\Enums\Visibility;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
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
            'bio' => fake()->sentence(),
            'preferred_languages' => ['en'],
            'reading_length' => ReadingLength::Any,
            'profile_visibility' => Visibility::Private,
            'library_visibility' => Visibility::Private,
            'personalization_enabled' => true,
            'show_mature' => false,
        ];
    }
}
