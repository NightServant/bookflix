<?php

use App\Access\MatureAccess;
use App\Discovery\Recommendations\ShelfBuilder;
use App\Enums\InteractionEvent;
use App\Enums\RecommendationEventType;
use App\Events\RecommendationDismissed;
use App\Jobs\RecordInteraction;
use App\Models\Recommendation;
use App\Models\RecommendationEvent;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

/*
 * What Home sends back about the tiles it showed: a click, the tiles that came into view, a dismissal and its undo. Every route answers 204
 * whatever the id, so an id that is not the reader's is ignored without saying whether it exists.
 */

beforeEach(function () {
    [$this->user, $this->catalog] = richReader();
    app(ShelfBuilder::class)->forHome($this->user);
    $this->rec = Recommendation::where('user_id', $this->user->id)->where('shelf', 'recommended')->first();
    $this->other = User::factory()->create();
});

test('every route needs a signed in, verified reader', function () {
    $this->post(route('recommendations.click'), ['recommendation_id' => $this->rec->id])->assertRedirect(route('login'));
    $this->post(route('recommendations.impressions'), ['ids' => [$this->rec->id]])->assertRedirect(route('login'));
    $this->post(route('recommendations.dismiss', $this->rec->id))->assertRedirect(route('login'));
    $this->delete(route('recommendations.undo', $this->rec->id))->assertRedirect(route('login'));

    expect(RecommendationEvent::count())->toBe(0);
});

test('the routes are CSRF protected (web group) and throttled', function () {
    foreach (['recommendations.click', 'recommendations.impressions', 'recommendations.dismiss', 'recommendations.undo'] as $name) {
        $middleware = collect(Route::getRoutes()->getByName($name)->gatherMiddleware());

        expect($middleware->contains('web'))->toBeTrue()
            ->and($middleware->contains(fn ($m) => str_starts_with((string) $m, 'throttle:')))->toBeTrue()
            ->and($middleware->contains('auth'))->toBeTrue();
    }
});

test('the click route is throttled', function () {
    foreach (range(1, 120) as $i) {
        $this->actingAs($this->user)->post(route('recommendations.click'), ['recommendation_id' => 999999])->assertNoContent();
    }

    $this->actingAs($this->user)->post(route('recommendations.click'), ['recommendation_id' => 999999])->assertStatus(429);
});

test('a click records the event and a recommendation_click interaction, and remembers the id for the story', function () {
    Queue::fake();

    $this->actingAs($this->user)->post(route('recommendations.click'), ['recommendation_id' => $this->rec->id])->assertNoContent();

    expect(RecommendationEvent::where('recommendation_id', $this->rec->id)->where('event', 'click')->count())->toBe(1)
        ->and(session("recommendation_clicks.{$this->rec->media_id}"))->toBe($this->rec->id);
    Queue::assertPushed(RecordInteraction::class, fn (RecordInteraction $job) => $job->event === InteractionEvent::RecommendationClick
        && $job->mediaId === $this->rec->media_id && $job->context['recommendation_id'] === $this->rec->id && $job->context['shelf'] === 'recommended');
});

test('a click on someone else\'s recommendation is ignored', function () {
    Queue::fake();

    $this->actingAs($this->other)->post(route('recommendations.click'), ['recommendation_id' => $this->rec->id])->assertNoContent();

    expect(RecommendationEvent::count())->toBe(0)->and(session('recommendation_clicks'))->toBeNull();
    Queue::assertNothingPushed();
});

test('an opted out reader\'s click leaves no event and no interaction', function () {
    Queue::fake();
    $this->user->profile->update(['personalization_enabled' => false]);

    $this->actingAs($this->user->fresh())->post(route('recommendations.click'), ['recommendation_id' => $this->rec->id])->assertNoContent();

    expect(RecommendationEvent::count())->toBe(0)->and(session('recommendation_clicks'))->toBeNull();
    Queue::assertNothingPushed();
});

test('a malformed click is refused', function () {
    $this->actingAs($this->user)->postJson(route('recommendations.click'), ['recommendation_id' => 'abc'])->assertUnprocessable();
});

test('adding the story afterwards from its page records the library add once', function () {
    $this->actingAs($this->user)->post(route('recommendations.click'), ['recommendation_id' => $this->rec->id])->assertNoContent();

    Livewire\Livewire::test('library-controls', ['media' => $this->rec->media])->call('add');
    Livewire\Livewire::test('library-controls', ['media' => $this->rec->media])->call('add');

    expect(RecommendationEvent::where('recommendation_id', $this->rec->id)->where('event', 'library_add')->count())->toBe(1);
});

test('adding a story that was never clicked from a tile records no library add', function () {
    $media = $this->catalog->last();

    $this->actingAs($this->user);
    Livewire\Livewire::test('library-controls', ['media' => $media])->call('add');

    expect(RecommendationEvent::where('event', 'library_add')->count())->toBe(0);
});

test('impressions are recorded for the tiles that are the reader\'s, in one request', function () {
    $own = Recommendation::where('user_id', $this->user->id)->limit(3)->pluck('id')->all();
    $foreign = Recommendation::factory()->create(['user_id' => $this->other->id]);

    $this->actingAs($this->user)->post(route('recommendations.impressions'), ['ids' => [...$own, $foreign->id, 999999]])->assertNoContent();

    expect(RecommendationEvent::where('event', 'impression')->pluck('recommendation_id')->sort()->values()->all())->toBe(collect($own)->sort()->values()->all());
});

test('impressions of an opted out reader are ignored, and the list has a size limit', function () {
    $this->actingAs($this->user)->postJson(route('recommendations.impressions'), ['ids' => range(1, 101)])->assertUnprocessable();

    $this->user->profile->update(['personalization_enabled' => false]);
    $this->actingAs($this->user->fresh())->post(route('recommendations.impressions'), ['ids' => [$this->rec->id]])->assertNoContent();

    expect(RecommendationEvent::count())->toBe(0);
});

test('a dismissal is recorded for the owner and tells the cache to forget', function () {
    Event::fake([RecommendationDismissed::class]);

    $this->actingAs($this->user)->post(route('recommendations.dismiss', $this->rec->id))->assertNoContent();

    expect(RecommendationEvent::where('recommendation_id', $this->rec->id)->where('event', 'dismiss')->count())->toBe(1);
    Event::assertDispatched(RecommendationDismissed::class);
});

test('a dismissal of someone else\'s recommendation is ignored', function () {
    $this->actingAs($this->other)->post(route('recommendations.dismiss', $this->rec->id))->assertNoContent();

    expect(RecommendationEvent::count())->toBe(0);
});

test('undo deletes the reader\'s own dismissal and forgets the cache', function () {
    $this->actingAs($this->user)->post(route('recommendations.dismiss', $this->rec->id))->assertNoContent();
    app(ShelfBuilder::class)->forHome($this->user);
    $key = 'home:shelves:'.$this->user->id.':'.MatureAccess::cacheKey($this->user);
    expect(Cache::has($key))->toBeTrue();

    $this->actingAs($this->user)->delete(route('recommendations.undo', $this->rec->id))->assertNoContent();

    expect(RecommendationEvent::where('event', 'dismiss')->count())->toBe(0)->and(Cache::has($key))->toBeFalse();
});

test('undo cannot remove someone else\'s dismissal, nor other kinds of event', function () {
    $this->actingAs($this->user)->post(route('recommendations.dismiss', $this->rec->id));
    RecommendationEvent::factory()->create(['recommendation_id' => $this->rec->id, 'event' => RecommendationEventType::Click]);

    $this->actingAs($this->other)->delete(route('recommendations.undo', $this->rec->id))->assertNoContent();
    expect(RecommendationEvent::where('event', 'dismiss')->count())->toBe(1);

    $this->actingAs($this->user)->delete(route('recommendations.undo', $this->rec->id))->assertNoContent();
    expect(RecommendationEvent::where('event', 'dismiss')->count())->toBe(0)->and(RecommendationEvent::where('event', 'click')->count())->toBe(1);
});
