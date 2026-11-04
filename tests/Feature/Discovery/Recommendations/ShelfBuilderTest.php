<?php

use App\Actions\Library\AddToLibrary;
use App\Discovery\Recommendations\ShelfBuilder;
use App\Enums\ReadingStatus;
use App\Models\Genre;
use App\Models\Media;
use App\Models\ModelVersion;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

const PERSONAL_SHELVES = ['recommended', 'because-you-read', 'similar-to'];

function homeShelves(User $user): Collection
{
    return app(ShelfBuilder::class)->forHome($user);
}

function shelfIds(Collection $shelves, string $key): array
{
    return $shelves->firstWhere('key', $key)->items->pluck('id')->all();
}

test('a reader with no interactions gets at least four non empty shelves and no personal ones', function () {
    homeCatalog();
    $user = User::factory()->create();

    $shelves = homeShelves($user);

    expect($shelves->count())->toBeGreaterThanOrEqual(4)
        ->and($shelves->filter(fn ($shelf) => $shelf->items->isEmpty()))->toBeEmpty()
        ->and($shelves->pluck('key')->all())->toContain('new-releases', 'trending', 'explore-by-subject')
        ->and($shelves->pluck('key')->all())->not->toContain(...PERSONAL_SHELVES);
});

test('a reader with a single interaction does not error and gets the cold start shelves', function () {
    $catalog = homeCatalog();
    $user = User::factory()->create();
    app(AddToLibrary::class)->handle($user, $catalog->first(), ReadingStatus::Completed);

    $keys = homeShelves($user)->pluck('key')->all();

    expect($keys)->toContain('trending', 'new-releases')->and($keys)->not->toContain(...PERSONAL_SHELVES);
});

test('a reader who opted out gets non personalized shelves only, with nothing logged', function () {
    [$user] = richReader();
    $user->profile->update(['personalization_enabled' => false]);

    $keys = homeShelves($user->fresh())->pluck('key')->all();

    expect($keys)->not->toContain(...PERSONAL_SHELVES)
        ->and($keys)->not->toContain('discover-new', 'popular-in-genres')
        ->and(count($keys))->toBeGreaterThanOrEqual(4)
        ->and(Recommendation::count())->toBe(0);
});

test('a rich reader gets the personalized shelves in master plan order with their titles', function () {
    [$user, $catalog] = richReader();

    $shelves = homeShelves($user);
    $order = ['continue-reading', 'recommended', 'because-you-read', 'new-releases', 'similar-to', 'trending', 'popular-in-genres', 'discover-new', 'collection-', 'explore-by-subject'];
    $position = fn (string $key): int => collect($order)->search(fn (string $prefix) => str_starts_with($key, $prefix));

    expect($shelves->pluck('key')->all())->toContain(...PERSONAL_SHELVES)
        ->and($shelves->pluck('key')->map($position)->all())->toBe($shelves->pluck('key')->map($position)->sort()->values()->all())
        ->and($shelves->firstWhere('key', 'recommended')->title)->toBe('Recommended For You')
        ->and($shelves->firstWhere('key', 'because-you-read')->title)->toBe('Because You Read '.$catalog[0]->title)
        ->and($shelves->firstWhere('key', 'similar-to')->title)->toBe('Similar To '.$catalog[7]->title)
        ->and($shelves->firstWhere('key', 'trending')->title)->toBe('Trending Now')
        ->and($shelves->filter(fn ($shelf) => $shelf->items->isEmpty()))->toBeEmpty();
});

test('the tiles carry the reason the reader will read', function () {
    [$user, $catalog] = richReader();

    $shelves = homeShelves($user);

    expect($shelves->firstWhere('key', 'because-you-read')->items->first()->reason_label)->toBe('Because you read '.$catalog[0]->title)
        ->and($shelves->firstWhere('key', 'similar-to')->items->first()->reason_label)->toBe('Similar themes to '.$catalog[7]->title)
        ->and($shelves->firstWhere('key', 'trending')->items->first()->reason_label)->toBe('Trending now')
        ->and($shelves->firstWhere('key', 'new-releases')->items->first()->reason_label)->toBe('New release');
});

test('trending is the only featured shelf', function () {
    [$user] = richReader();

    expect(homeShelves($user)->where('featured', true)->pluck('key')->all())->toBe(['trending']);
});

test('the reader\'s library never appears on a candidate shelf', function () {
    [$user] = richReader();
    $library = $user->library()->pluck('media_id')->all();

    $shelves = homeShelves($user)->reject(fn ($shelf) => $shelf->key === 'continue-reading' || str_starts_with($shelf->key, 'collection-') || $shelf->key === 'explore-by-subject');

    expect($shelves->count())->toBeGreaterThan(4);

    foreach ($shelves as $shelf) {
        expect($shelf->items->pluck('id')->intersect($library)->all())->toBe([], $shelf->key);
    }
});

test('deleted, merged and old model version stories never appear', function () {
    [$user, $catalog] = richReader();
    $deleted = $catalog[10];
    $merged = $catalog[11];
    $deleted->delete();
    $merged->update(['merged_into_id' => $catalog[13]->id]);
    $candidate = ModelVersion::factory()->create(['name' => 'lsa', 'kind' => 'lsa', 'status' => 'candidate']);
    $unknown = Media::factory()->create(['title' => 'Only A Candidate Knows']);
    similar($catalog[0], $unknown, 'lsa', 0.99, $candidate);

    $shelves = homeShelves($user);
    $ids = $shelves->flatMap(fn ($shelf) => $shelf->items->pluck('id'));
    // Only a neighbor row can carry the unknown story to these shelves (the exploration pool, which feeds two other shelves, may draw any story).
    $personalIds = $shelves->whereIn('key', ['because-you-read', 'similar-to'])->flatMap(fn ($shelf) => $shelf->items->pluck('id'));

    expect($ids)->not->toContain($deleted->id, $merged->id)
        ->and($personalIds)->not->toContain($unknown->id);
});

test('mature stories never reach a reader without access, in any shelf or cache entry', function () {
    [$user, $catalog] = richReader();
    $mature = matureStory('Forbidden Fruit');
    $mature->update(['published_on' => now()->subDay(), 'ratings_count' => 5000, 'popularity_score' => 1, 'average_rating' => 4.9]);
    similar($catalog[0], $mature, 'lsa', 0.99, activeVersion('lsa'));
    similar($catalog[7], $mature, 'lsa', 0.99, activeVersion('lsa'));
    DB::statement('refresh materialized view media_popularity');

    $shelves = homeShelves($user);
    $cached = json_encode(Cache::get('home:shelves:'.$user->id.':gated'));

    expect($shelves->flatMap(fn ($shelf) => $shelf->items->pluck('id'))->contains($mature->id))->toBeFalse()
        ->and(json_encode($shelves->map(fn ($shelf) => [$shelf->title, $shelf->items->pluck('reason_label')])))->not->toContain('Forbidden')
        ->and($cached)->not->toContain('Forbidden')->and($cached)->not->toContain((string) $mature->id.',')
        ->and(Recommendation::where('media_id', $mature->id)->count())->toBe(0);
});

test('a mature seed is not named for a reader without access', function () {
    [$user, $catalog] = richReader();
    $catalog[0]->genres()->attach(Genre::factory()->mature()->create());

    $shelves = homeShelves($user);

    expect($shelves->pluck('key')->all())->not->toContain('because-you-read')
        ->and(json_encode($shelves->map(fn ($shelf) => $shelf->title)))->not->toContain($catalog[0]->title);
});

test('an empty catalog and a reader with nothing produce an empty list instead of an error', function () {
    expect(homeShelves(User::factory()->create()))->toBeEmpty();
});

test('the container\'s randomizer decides the exploration slots', function () {
    [$user] = richReader();
    $order = function (int $seed) use ($user): array {
        Cache::flush();
        app()->bind(Randomizer::class, fn () => new Randomizer(new Mt19937($seed)));

        return shelfIds(app()->make(ShelfBuilder::class)->forHome($user), 'discover-new');
    };

    expect($order(5))->toBe($order(5))->and($order(5))->not->toBe($order(6));
});

test('building the shelves of a rich reader takes a bounded number of queries', function () {
    [$user] = richReader();
    DB::enableQueryLog();

    homeShelves($user);

    $queries = count(DB::getQueryLog());
    expect($queries)->toBeLessThanOrEqual(100);
    fwrite(STDERR, "\nforHome cold cache: {$queries} queries\n");
});
