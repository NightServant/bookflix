<?php

namespace Database\Factories;

use App\Enums\InteractionEvent;
use App\Models\Media;
use App\Models\User;
use App\Models\UserInteraction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserInteraction>
 */
class UserInteractionFactory extends Factory
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
            'media_id' => Media::factory(),
            'event' => InteractionEvent::View,
            'context' => [],
            'occurred_at' => now(),
        ];
    }
}
