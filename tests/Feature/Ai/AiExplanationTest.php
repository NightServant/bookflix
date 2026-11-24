<?php

use App\Ai\Explanations\LlmExplainer;
use App\Enums\ReasonType;
use App\Models\Media;
use App\Models\ModelVersion;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('bookflix.ai', array_merge(config('bookflix.ai'), [
        'enabled' => true,
        'base_url' => 'https://llm.test/v1',
        'api_key' => 'sk-test',
        'models' => ['fast' => 'tiny', 'smart' => 'big'],
    ]));
    Sleep::fake();
    Cache::flush();
});

function explainReply(string $sentence): array
{
    return ['choices' => [['message' => ['role' => 'assistant', 'content' => json_encode(['sentence' => $sentence])], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20]];
}

/** @return array{0: User, 1: Media, 2: Media} */
function explainSetup(bool $optIn = true): array
{
    $user = User::factory()->create();
    $user->profile->update(['ai_explanations_enabled' => $optIn]);
    $media = Media::factory()->create(['title' => 'Dracula', 'description' => '<p>A count moves to England.</p>']);
    $reason = Media::factory()->create(['title' => 'Frankenstein', 'description' => 'A scientist builds a creature.']);

    return [$user->refresh(), $media, $reason];
}

it('returns the AI sentence, sends only the two stories facts and leaves the visible reason line alone', function () {
    Http::fake(['*' => Http::response(explainReply('Both are gothic horror classics, so "Dracula" suits a fan of "Frankenstein".'))]);
    [$user, $media, $reason] = explainSetup();
    $other = Media::factory()->create(['title' => 'Zanzibar Chronicle']);

    $sentence = app(LlmExplainer::class)->explain($user, $media, ReasonType::BecauseYouRead, $reason);

    expect($sentence)->toBe('Both are gothic horror classics, so "Dracula" suits a fan of "Frankenstein".');

    Http::assertSent(function (Request $request) use ($user, $other) {
        $body = json_encode($request->data());

        return $request['model'] === 'tiny'
            && str_contains($body, 'Dracula') && str_contains($body, 'Frankenstein') && str_contains($body, 'Because you read Frankenstein')
            && ! str_contains($body, $other->title) && ! str_contains($body, $user->email) && ! str_contains($body, $user->name)
            && ! array_key_exists('user', $request->data())
            && $request['response_format'] === ['type' => 'json_object'];
    });
    expect(ModelVersion::where('name', 'explanation-prompt')->where('version', 'v1+tiny')->exists())->toBeTrue();
});

it('is reached from the home page, validates its arguments and leaves hidden stories out', function () {
    Http::fake(['*' => Http::response(explainReply('Both are classic gothic tales.'))]);
    [$user, $media, $reason] = explainSetup();
    $hidden = Media::factory()->create(['age_rating' => 'adult']);
    Recommendation::factory()->create(['user_id' => $user->id, 'media_id' => $media->id, 'reason_type' => 'because_you_read', 'reason_media_id' => $reason->id]);
    Recommendation::factory()->create(['user_id' => $user->id, 'media_id' => $hidden->id, 'reason_type' => 'trending', 'reason_media_id' => null]);
    Recommendation::factory()->create(['user_id' => $user->id, 'media_id' => $media->id, 'reason_type' => 'because_you_read', 'reason_media_id' => $hidden->id]);
    $this->actingAs($user);

    Livewire::test('pages::home.index')
        ->call('explain', $media->id, 'because_you_read', $reason->id)->assertReturned('Both are classic gothic tales.')
        ->call('explain', $media->id, 'nonsense', null)->assertHasErrors(['reasonType']);

    Http::fake();
    Livewire::test('pages::home.index')
        ->call('explain', $hidden->id, 'trending', null)->assertReturned(null)
        ->call('explain', $media->id, 'because_you_read', $hidden->id)->assertReturned(null);
    Http::assertNothingSent();
});

it('explains only a reason that is one of the readers own recommendation rows', function () {
    Http::fake(['*' => Http::response(explainReply('Both are classic gothic tales.'))]);
    [$user, $media, $reason] = explainSetup();
    $other = Media::factory()->create();
    Recommendation::factory()->create(['user_id' => $user->id, 'media_id' => $media->id, 'reason_type' => 'because_you_read', 'reason_media_id' => $reason->id]);
    Recommendation::factory()->create(['user_id' => User::factory()->create()->id, 'media_id' => $other->id, 'reason_type' => 'trending', 'reason_media_id' => null]);
    $this->actingAs($user);

    Http::fake();
    Livewire::test('pages::home.index')
        ->call('explain', $media->id, 'because_you_read', null)->assertReturned(null);
    Livewire::test('pages::home.index')->call('explain', $media->id, 'because_you_read', $other->id)->assertReturned(null);
    Http::assertNothingSent();
});

it('sends nothing for a reader who has not opted in, turned personalization off, or while AI is off', function (string $case) {
    Http::fake();
    [$user, $media, $reason] = explainSetup(optIn: $case !== 'toggle');

    if ($case === 'personalization') {
        $user->profile->update(['personalization_enabled' => false]);
    }

    if ($case === 'ai-off') {
        config()->set('bookflix.ai.enabled', false);
    }

    expect(app(LlmExplainer::class)->explain($user->refresh(), $media, ReasonType::BecauseYouRead, $reason))->toBeNull();
    Http::assertNothingSent();
})->with(['toggle', 'personalization', 'ai-off']);

it('sends nothing for a story that matches the prohibited list', function () {
    Http::fake();
    [$user, $media, $reason] = explainSetup();
    $reason->update(['description' => 'a lolicon story']);

    expect(app(LlmExplainer::class)->explain($user, $media, ReasonType::BecauseYouRead, $reason))->toBeNull();
    Http::assertNothingSent();
});

it('discards a sentence over 30 words, with a link, markdown or a title that is not one of the two', function (string $sentence) {
    Http::fake(['*' => Http::response(explainReply($sentence))]);
    [$user, $media, $reason] = explainSetup();
    Media::factory()->create(['title' => 'Moby Dick']);

    expect(app(LlmExplainer::class)->explain($user, $media, ReasonType::BecauseYouRead, $reason))->toBeNull();
})->with([
    'too long' => [str_repeat('word ', 31)],
    'link' => ['Read more at https://example.com now.'],
    'markdown' => ['A **bold** pick for you.'],
    'another story' => ['It is like "Moby Dick" at sea.'],
    'invented title' => ['It is like "The Nonexistent Tome".'],
]);

it('caches a sentence for a week and does not ask again', function () {
    Http::fake(['*' => Http::response(explainReply('A gothic pick.'))]);
    [$user, $media, $reason] = explainSetup();
    $explainer = app(LlmExplainer::class);

    expect($explainer->explain($user, $media, ReasonType::BecauseYouRead, $reason))->toBe('A gothic pick.')
        ->and($explainer->explain($user, $media, ReasonType::BecauseYouRead, $reason))->toBe('A gothic pick.');
    Http::assertSentCount(1);

    $this->travel(8)->days();
    expect($explainer->explain($user, $media, ReasonType::BecauseYouRead, $reason))->toBe('A gothic pick.');
    Http::assertSentCount(2);
});

it('is null when the provider fails', function () {
    [$user, $media, $reason] = explainSetup();
    Http::fake(['*' => Http::response('boom', 500)]);

    expect(app(LlmExplainer::class)->explain($user, $media, ReasonType::BecauseYouRead, $reason))->toBeNull();
});

it('is null when the reader is rate limited', function () {
    [$user, $media] = explainSetup();
    Http::fake(['*' => Http::response(explainReply('A gothic pick.'))]);
    RateLimiter::for('ai-explanations', fn () => Limit::perHour(1)->by('t'));
    $other = Media::factory()->create(['title' => 'Carmilla']);

    expect(app(LlmExplainer::class)->explain($user, $media, ReasonType::Trending, null))->toBe('A gothic pick.')
        ->and(app(LlmExplainer::class)->explain($user, $other, ReasonType::Trending, null))->toBeNull();
});

it('offers Why this? on reasoned tiles only with AI and the opt-in on, and the reason line stays', function () {
    [$user] = richReader();
    Http::fake();
    $off = $this->actingAs($user)->get(route('home'))->assertOk()->getContent();

    expect($off)->not->toContain('Why this?')->and($off)->not->toContain('whyThis');
    $reasonLines = htmlQuery($off, '//section[@aria-labelledby="shelf-recommended"]//span[contains(@class,"line-clamp-3")]')->length;
    expect($reasonLines)->toBeGreaterThan(0);

    $user->profile->update(['ai_explanations_enabled' => true]);
    $on = $this->actingAs($user->refresh())->get(route('home'))->assertOk()->getContent();

    expect(htmlQuery($on, '//section[@aria-labelledby="shelf-recommended"]//button[@aria-controls and contains(.,"Why this?")]')->length)->toBe($reasonLines)
        ->and(htmlQuery($on, '//section[@aria-labelledby="shelf-recommended"]//span[contains(@class,"line-clamp-3")]')->length)->toBe($reasonLines)
        ->and(htmlQuery($on, '//section[@aria-labelledby="shelf-because-you-read"]//button[contains(.,"Why this?")]')->length)->toBe(0);
    Http::assertNothingSent();

    config()->set('bookflix.ai.enabled', false);
    expect($this->actingAs($user->refresh())->get(route('home'))->getContent())->not->toContain('Why this?');
});

it('shows the AI features section on the privacy page and saves the toggle', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('privacy.edit'))->assertOk()->assertSee('id="ai"', false)->assertSee('AI explanations')->assertSee('What we send')->assertSee('outside AI service')->assertSee('words you search for go to the AI service')->assertDontSee('nothing is sent');

    Livewire::test('pages::settings.privacy')->set('aiExplanationsEnabled', true)->call('updateAi')->assertHasNoErrors();
    expect($user->refresh()->profile->ai_explanations_enabled)->toBeTrue();

    $user->profile->update(['personalization_enabled' => false]);
    Livewire::test('pages::settings.privacy')->set('aiExplanationsEnabled', true)->call('updateAi');
    expect($user->refresh()->profile->ai_explanations_enabled)->toBeFalse();

    config()->set('bookflix.ai.enabled', false);
    $this->get(route('privacy.edit'))->assertDontSee('What we send');
});
