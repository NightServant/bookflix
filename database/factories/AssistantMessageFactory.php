<?php

namespace Database\Factories;

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssistantMessage>
 */
class AssistantMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assistant_conversation_id' => AssistantConversation::factory(),
            'role' => 'user',
            'text' => fake()->sentence(),
        ];
    }

    public function assistant(): static
    {
        return $this->state(['role' => 'assistant']);
    }
}
