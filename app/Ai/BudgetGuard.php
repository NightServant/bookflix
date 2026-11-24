<?php

namespace App\Ai;

use App\Models\AiUsage;
use App\Models\User;

class BudgetGuard
{
    /**
     * A user who spent today's token cap gets no more calls. Calls without a user (the shared daily features) are not capped per user.
     *
     * @throws LlmUnavailable
     */
    public function check(?User $user, string $feature): void
    {
        if ($user === null) {
            return;
        }

        $spent = (int) AiUsage::where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->selectRaw('coalesce(sum(input_tokens + output_tokens), 0) as total')
            ->value('total');

        if ($spent >= (int) config('bookflix.ai.daily_token_cap')) {
            throw new LlmUnavailable("The daily AI budget is spent ({$feature}).");
        }
    }

    public function record(?User $user, string $feature, string $model, int $input, int $output, int $cached): void
    {
        AiUsage::create([
            'user_id' => $user?->id,
            'feature' => $feature,
            'model' => $model,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cached_input_tokens' => $cached,
        ]);
    }
}
