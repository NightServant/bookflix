<?php

use App\Ai\BudgetGuard;
use App\Ai\LlmUnavailable;
use App\Models\AiUsage;
use App\Models\User;

beforeEach(fn () => config()->set('bookflix.ai.daily_token_cap', 1000));

it('allows a user under the cap', function () {
    $user = User::factory()->create();
    AiUsage::factory()->create(['user_id' => $user->id, 'input_tokens' => 400, 'output_tokens' => 100]);

    app(BudgetGuard::class)->check($user, 'intent');

    expect(true)->toBeTrue();
});

it('blocks a user at or over the cap, counting input and output together', function () {
    $user = User::factory()->create();
    AiUsage::factory()->create(['user_id' => $user->id, 'input_tokens' => 800, 'output_tokens' => 200]);

    expect(fn () => app(BudgetGuard::class)->check($user, 'intent'))->toThrow(LlmUnavailable::class);
});

it('counts only that user and allows them again the next day', function () {
    $user = User::factory()->create();
    AiUsage::factory()->create(['user_id' => User::factory()->create()->id, 'input_tokens' => 5000]);
    AiUsage::factory()->create(['user_id' => $user->id, 'input_tokens' => 2000]);

    expect(fn () => app(BudgetGuard::class)->check($user, 'intent'))->toThrow(LlmUnavailable::class);

    $this->travelTo(now()->addDay()->startOfDay()->addHour());

    app(BudgetGuard::class)->check($user, 'intent');

    expect(true)->toBeTrue();
});

it('does not cap a call without a user and records it with a null user', function () {
    AiUsage::factory()->create(['user_id' => null, 'input_tokens' => 99999]);
    $guard = app(BudgetGuard::class);

    $guard->check(null, 'summary');
    $guard->record(null, 'summary', 'big-model', 10, 5, 2);

    expect(AiUsage::whereNull('user_id')->count())->toBe(2);
});
