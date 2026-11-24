<?php

namespace Database\Factories;

use App\Models\AiUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsage>
 */
class AiUsageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'feature' => 'intent',
            'model' => 'fast-model',
            'input_tokens' => 100,
            'output_tokens' => 20,
            'cached_input_tokens' => 0,
        ];
    }
}
