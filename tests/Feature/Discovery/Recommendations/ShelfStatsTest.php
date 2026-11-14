<?php

use App\Discovery\Recommendations\ShelfBandit;
use App\Discovery\Recommendations\ShelfBuilder;
use App\Models\Recommendation;
use App\Models\RecommendationEvent;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Random\Engine\Mt19937;
use Random\Randomizer;

function shelfKeys(User $user): array
{
    $stats = Cache::get(ShelfBandit::CACHE_KEY);
    Cache::flush();
    $stats === null || Cache::put(ShelfBandit::CACHE_KEY, $stats, now()->addHours(3));

    return app(ShelfBuilder::class)->forHome($user)->pluck('key')->all();
}

function statsOf(array $stats): void
{
    Cache::put(ShelfBandit::CACHE_KEY, $stats, now()->addHours(3));
}

function loggedRow(string $shelf, array $events, $generatedAt = null): Recommendation
{
    $row = Recommendation::factory()->create(['shelf' => $shelf, 'generated_at' => $generatedAt ?? now()]);

    foreach ($events as $event) {
        RecommendationEvent::factory()->create(['recommendation_id' => $row->id, 'event' => $event]);
    }

    return $row;
}

/** Sampling stats that put Discover Something New first and Similar To last. */
function extremeStats(): array
{
    return ['similar-to' => [5000, 0], 'popular-in-genres' => [5000, 2500], 'discover-new' => [5000, 4990]];
}

test('the stats command counts rows, not events, and one click per row', function () {
    loggedRow('discover-new', ['impression', 'impression', 'impression', 'click', 'click']);
    loggedRow('discover-new', ['impression']);
    loggedRow('discover-new', []);
    loggedRow('similar-to', ['click', 'dismiss']);
    loggedRow('similar-to', ['impression', 'library_add']);

    $this->artisan('bookflix:refresh-shelf-stats')->assertSuccessful();

    expect(Cache::get(ShelfBandit::CACHE_KEY))->toBe(['discover-new' => [2, 1], 'similar-to' => [1, 1]]);
});

test('the stats command leaves out rows older than the retention window', function () {
    config(['bookflix.recommendations.retention_days' => 90]);
    loggedRow('discover-new', ['impression', 'click'], now()->subDays(89));
    loggedRow('discover-new', ['impression', 'click'], now()->subDays(91));

    $this->artisan('bookflix:refresh-shelf-stats');

    expect(Cache::get(ShelfBandit::CACHE_KEY))->toBe(['discover-new' => [1, 1]]);
});

test('the stats command is scheduled hourly', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'bookflix:refresh-shelf-stats'));

    expect($event->expression)->toBe('20 * * * *');
});

test('with the flag off, or on with no stats or too few impressions, Home is the default order', function () {
    [$user] = richReader();
    $default = shelfKeys($user);

    config(['bookflix.recommendations.bandit_enabled' => false]);
    statsOf(extremeStats());
    expect(shelfKeys($user))->toBe($default);

    config(['bookflix.recommendations.bandit_enabled' => true]);
    Cache::flush();
    expect(shelfKeys($user))->toBe($default);

    statsOf(['similar-to' => [999, 0], 'popular-in-genres' => [999, 999], 'discover-new' => [10, 10]]);
    expect(shelfKeys($user))->toBe($default)
        ->and(config('bookflix.recommendations.bandit_enabled'))->toBeTrue();
});

test('the flag defaults to false', function () {
    expect(config('bookflix.recommendations.bandit_enabled'))->toBeFalse();
});

test('with the flag on, fixed shelves keep their places and no shelf is dropped or duplicated', function () {
    [$user] = richReader();
    $default = shelfKeys($user);
    config(['bookflix.recommendations.bandit_enabled' => true]);
    $moved = false;

    foreach (range(1, 12) as $seed) {
        app()->bind(Randomizer::class, fn () => new Randomizer(new Mt19937($seed)));
        Cache::flush();
        statsOf(extremeStats());
        $keys = app()->make(ShelfBuilder::class)->forHome($user)->pluck('key')->all();

        foreach ($default as $position => $key) {
            if (! in_array($key, ShelfBandit::REORDERABLE, true)) {
                expect($keys[$position])->toBe($key);
            }
        }
        expect(collect($keys)->sort()->values()->all())->toBe(collect($default)->sort()->values()->all());
        $moved = $moved || $keys !== $default;
    }

    expect($moved)->toBeTrue();
});

test('what a shelf holds does not change with the order', function () {
    [$user] = richReader();
    $items = fn () => app(ShelfBuilder::class)->forHome($user)->mapWithKeys(fn ($shelf) => [$shelf->key => $shelf->items->pluck('id')->all()])->all();
    // The exploration draw is random too, so both builds start from the same seed.
    app()->bind(Randomizer::class, fn () => new Randomizer(new Mt19937(2)));
    Cache::flush();
    $default = $items();
    config(['bookflix.recommendations.bandit_enabled' => true]);
    app()->bind(Randomizer::class, fn () => new Randomizer(new Mt19937(2)));
    Cache::flush();
    statsOf(extremeStats());

    expect($items())->toEqualCanonicalizing($default);
});

test('the sample is drawn once per cache fill: a second read returns the same order', function () {
    [$user] = richReader();
    config(['bookflix.recommendations.bandit_enabled' => true]);
    Cache::flush();
    statsOf(['similar-to' => [3000, 300], 'popular-in-genres' => [3000, 310], 'discover-new' => [3000, 305]]);
    $builder = fn () => app()->make(ShelfBuilder::class)->forHome($user)->pluck('key')->all();

    $first = $builder();
    // A different draw would follow if the order were sampled per request.
    app()->bind(Randomizer::class, fn () => new Randomizer(new Mt19937(99)));

    expect($builder())->toBe($first)->and($builder())->toBe($first);
});

test('opted out and cold readers are not reordered', function () {
    homeCatalog();
    $cold = User::factory()->create();
    [$optedOut] = richReader();
    $optedOut->profile->update(['personalization_enabled' => false]);
    $keysOf = fn (User $user) => shelfKeys($user->fresh());
    $before = [$keysOf($cold), $keysOf($optedOut)];

    config(['bookflix.recommendations.bandit_enabled' => true]);
    foreach (range(1, 5) as $seed) {
        app()->bind(Randomizer::class, fn () => new Randomizer(new Mt19937($seed)));
        Cache::flush();
        statsOf(extremeStats());

        expect([$keysOf($cold), $keysOf($optedOut)])->toBe($before);
    }
});
