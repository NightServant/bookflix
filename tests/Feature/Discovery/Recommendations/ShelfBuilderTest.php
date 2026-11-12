<?php

use App\Actions\Library\AddToLibrary;
use App\Actions\Library\LogProgress;
use App\Actions\Ratings\RateMedia;
use App\Discovery\Recommendations\CandidatePool;
use App\Discovery\Recommendations\ExplorationInjector;
use App\Discovery\Recommendations\SeedItems;
use App\Discovery\Recommendations\ServedItem;
use App\Discovery\Recommendations\ShelfBuilder;
use App\Discovery\Recommendations\Sources\SemanticSimilarSource;
use App\Enums\InteractionEvent;
use App\Enums\ReadingStatus;
use App\Enums\ReasonType;
use App\Models\Genre;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\ModelVersion;
use App\Models\Rating;
use App\Models\Recommendation;
use App\Models\User;
use App\Models\UserInteraction;
use App\Providers\RecommendationServiceProvider;
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

test('a mature seed is not named for a reader without access, the next seed heads the shelf', function () {
    [$user, $catalog] = richReader();
    $catalog[0]->genres()->attach(Genre::factory()->mature()->create());

    $shelves = homeShelves($user);

    expect(json_encode($shelves->map(fn ($shelf) => $shelf->title)))->not->toContain($catalog[0]->title)
        ->and($shelves->firstWhere('key', 'because-you-read')?->title)->toBe('Because You Read '.$catalog[1]->title);
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
    expect($queries)->toBeLessThanOrEqual(75);
    fwrite(STDERR, "\nforHome cold cache: {$queries} queries\n");

    DB::flushQueryLog();
    homeShelves($user);
    fwrite(STDERR, 'forHome warm cache: '.count(DB::getQueryLog())." queries\n");
});

test('the reader\'s seeds are read once per build and never kept between builds', function () {
    homeCatalog();
    [$user] = richReader();
    $ratingReads = fn (): int => collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'select "media_id", "score" from "ratings"'))->count();
    DB::enableQueryLog();

    homeShelves($user);
    expect($ratingReads())->toBe(1);

    // A change to the library after the build is seen by the next one, so nothing is remembered across builds.
    $before = app(SeedItems::class)->for($user)->count();
    $extra = Media::factory()->create();
    Rating::factory()->create(['user_id' => $user->id, 'media_id' => $extra->id, 'score' => 9]);
    Cache::flush();
    DB::flushQueryLog();
    homeShelves($user);

    expect($ratingReads())->toBe(1)
        ->and(app(SeedItems::class)->for($user)->count())->toBe($before + 1);
});

test('Because You Read and Similar To fill up from neighbors the shared pool cut off', function () {
    // The exploration source draws at random and can pick the quiet titles; a fixed seed keeps how many reach the pool the same on every run.
    app()->bind(Randomizer::class, fn () => new Randomizer(new Mt19937(1)));
    $catalog = homeCatalog();
    $user = User::factory()->create();
    $lsa = activeVersion('lsa');
    $crowd = Media::factory()->count(130)->create(['published_on' => now()->subYears(3), 'popularity_score' => 0.01]);
    $quiet = Media::factory()->count(25)->create(['published_on' => now()->subYears(3), 'popularity_score' => 0, 'ratings_count' => 0, 'average_rating' => 0]);

    // Older, loved seeds with far more and stronger neighbors than the pool holds crowd the recent seed's neighbors out of it.
    foreach ($catalog->slice(1, 5) as $i => $seed) {
        libraryEntry($user, $seed, 'completed')->update(['completed_at' => now()->subDays(10 + $i)]);
    }
    foreach ($catalog->slice(6, 2) as $seed) {
        libraryEntry($user, $seed, 'reading', favorite: true);
        foreach ($crowd as $j => $neighbor) {
            similar($seed, $neighbor, 'lsa', 0.9 - $j * 0.002, $lsa);
        }
    }
    libraryEntry($user, $catalog[0], 'completed')->update(['completed_at' => now()]);
    foreach ($quiet as $j => $neighbor) {
        similar($catalog[0], $neighbor, 'lsa', 0.2 - $j * 0.001, $lsa);
    }
    warm($user, $catalog->take(8));

    $pool = app(CandidatePool::class);
    $pooled = $pool->combine($user, $pool->bySource($user, 100))->pluck('mediaId');
    $shelf = homeShelves($user)->firstWhere('key', 'because-you-read');

    expect($pooled->intersect($quiet->pluck('id'))->count())->toBeLessThan(20)
        ->and($shelf->items)->toHaveCount(20)
        ->and($shelf->items->pluck('id')->diff($quiet->pluck('id')))->toBeEmpty();
});

test('the exploration share of Recommended For You is the configured epsilon', function () {
    [$user] = richReader();
    $every = [];
    app()->instance(ExplorationInjector::class, new class($every) extends ExplorationInjector
    {
        public function __construct(private array &$every) {}

        public function inject(Collection $ranked, Collection $exploration, int $every = 10, ?Randomizer $randomizer = null): Collection
        {
            $this->every[] = $every;

            return parent::inject($ranked, $exploration, $every, $randomizer);
        }
    });

    foreach ([null, 0.25, 0.0] as $epsilon) {
        Cache::flush();
        config(['bookflix.recommendations.epsilon' => $epsilon ?? 0.10]);
        homeShelves($user);
    }

    expect($every)->toBe([10, 4, 0]);
});

const CANDIDATE_KEYS = ['recommended', 'because-you-read', 'new-releases', 'similar-to', 'trending', 'popular-in-genres', 'discover-new'];

function candidateShelves(Collection $shelves): Collection
{
    return $shelves->filter(fn ($shelf) => in_array($shelf->key, CANDIDATE_KEYS, true))->values();
}

test('no title appears in two candidate shelves', function () {
    [$user] = richReader();

    $ids = candidateShelves(homeShelves($user))->flatMap(fn ($shelf) => $shelf->items->pluck('id'));

    expect($ids->count())->toBeGreaterThan(8)->and($ids->duplicates()->all())->toBe([]);
});

test('every candidate shelf is present for a rich reader with a crowded catalog', function () {
    [$user] = richReader();

    $shelves = candidateShelves(homeShelves($user));
    $ids = $shelves->flatMap(fn ($shelf) => $shelf->items->pluck('id'));

    expect($shelves->pluck('key')->all())->toBe(CANDIDATE_KEYS)->and($ids->duplicates()->all())->toBe([]);
});

test('a later shelf backfills from its next candidates and keeps its size', function () {
    [$user] = richReader();

    $shelves = candidateShelves(homeShelves($user))->keyBy('key');

    foreach (['recommended', 'because-you-read', 'new-releases', 'trending', 'popular-in-genres'] as $key) {
        expect($shelves[$key]->items)->toHaveCount(20, $key);
    }

    // Similar To has only the neighbors of one title, most of which an earlier shelf took: what is left is shown if it is enough.
    expect($shelves['similar-to']->items->count())->toBeGreaterThanOrEqual(4);
});

test('the staff picks and Explore by Subject are exempt from the de-duplication', function () {
    [$user] = richReader();
    $first = homeShelves($user)->firstWhere('key', 'recommended')->items->first();
    collectionOf(User::factory()->create(), [$first], ['is_editorial' => true, 'visibility' => 'public', 'name' => 'Picked Again']);
    Cache::flush();

    $shelves = homeShelves($user);
    $staff = $shelves->first(fn ($shelf) => str_starts_with($shelf->key, 'collection-') && $shelf->title === 'Picked Again');

    expect($staff->items->pluck('id')->all())->toContain($first->id)
        ->and($shelves->firstWhere('key', 'recommended')->items->pluck('id')->all())->toContain($first->id);
});

test('a candidate shelf left with fewer titles than the minimum is omitted', function () {
    [$user] = richReader();
    config(['bookflix.recommendations.min_shelf_size' => 15]);

    $shelves = candidateShelves(homeShelves($user));

    expect($shelves->pluck('key')->all())->not->toBe([])
        ->and($shelves->filter(fn ($shelf) => $shelf->key !== 'trending' && $shelf->items->count() < 15)->all())->toBe([]);
});

test('trending is never empty, for a cold reader or when earlier shelves took its best titles', function () {
    homeCatalog();
    $cold = User::factory()->create();

    $shelf = homeShelves($cold)->firstWhere('key', 'trending');
    expect($shelf)->not->toBeNull()->and($shelf->items)->not->toBeEmpty();

    Cache::flush();
    config(['bookflix.recommendations.min_shelf_size' => 1000]);
    $shelf = homeShelves($cold)->firstWhere('key', 'trending');

    expect($shelf)->not->toBeNull()->and($shelf->items)->not->toBeEmpty();
});

/**
 * The de-duplication on its own, so that what it does to Trending is exact: earlier shelves are given titles of their own and Trending's candidates
 * are chosen to be (all or most of) the same ones.
 *
 * @param  array<string, list<int>>  $shelves
 * @return array<string, list<int>>
 */
function deduplicated(array $shelves): array
{
    $served = array_map(fn (array $ids, string $key): array => array_map(fn (int $id): ServedItem => new ServedItem($key, $id, 1.0, ReasonType::Trending), $ids), $shelves, array_keys($shelves));
    $method = new ReflectionMethod(ShelfBuilder::class, 'withoutRepeats');

    return array_map(fn (array $items): array => array_map(fn (ServedItem $item): int => $item->mediaId, $items), $method->invoke(app(ShelfBuilder::class), array_combine(array_keys($shelves), $served)));
}

test('trending keeps its own titles when earlier shelves took every one of them', function () {
    $ids = range(1, 6);

    $kept = deduplicated(['recommended' => $ids, 'trending' => $ids]);

    expect($kept['recommended'])->toBe($ids)->and($kept['trending'])->toBe($ids);
});

test('trending never shows a few titles: with fewer than the minimum left it keeps all its own', function () {
    $trending = range(1, 10);

    // Earlier shelves took eight of the ten; two would be left.
    $kept = deduplicated(['recommended' => range(1, 8), 'trending' => $trending]);

    expect($kept['trending'])->toBe($trending);

    // With enough left (four, the minimum) it shows only those.
    $kept = deduplicated(['recommended' => range(1, 6), 'trending' => $trending]);

    expect($kept['trending'])->toBe([7, 8, 9, 10]);
});

test('the See all link of Because You Read leaves a mature genre out for a reader without access, and keeps it for one with', function () {
    $seed = Media::factory()->create();
    $seed->genres()->attach([Genre::factory()->create(['slug' => 'quiet'])->id, Genre::factory()->mature()->create(['slug' => 'steamy'])->id]);
    $gated = User::factory()->create();
    $open = User::factory()->create();
    $open->profile->update(['show_mature' => true, 'adult_confirmed_at' => now()]);
    $searchLike = new ReflectionMethod(ShelfBuilder::class, 'searchLike');
    $link = fn (User $user): string => urldecode($searchLike->invoke(app(ShelfBuilder::class), $user->fresh(), $seed));

    expect($link($gated))->toContain('quiet')->not->toContain('steamy')
        ->and($link($open))->toContain('quiet')->toContain('steamy');

    $onlyMature = Media::factory()->create();
    $onlyMature->genres()->attach(Genre::factory()->mature()->create(['slug' => 'only-steamy']));

    expect($searchLike->invoke(app(ShelfBuilder::class), $gated->fresh(), $onlyMature))->toBeNull();
});

test('what is logged is what is shown after de-duplication', function () {
    [$user] = richReader();

    $shown = candidateShelves(homeShelves($user))->flatMap(fn ($shelf) => $shelf->items->map(fn ($m) => $shelf->key.':'.$m->id))->sort()->values()->all();
    $logged = Recommendation::query()->get()->map(fn ($row) => $row->shelf.':'.$row->media_id)->sort()->values()->all();

    expect($logged)->toBe($shown);
});

test('a reader is cold until five strong interactions, and the exit path warms them', function () {
    $catalog = homeCatalog();
    $berserk = $catalog[0];
    $user = User::factory()->create();

    foreach (Media::factory()->count(30)->create() as $j => $neighbor) {
        similar($berserk, $neighbor, 'lsa', 0.9 - $j * 0.02, activeVersion('lsa'));
    }

    foreach ($catalog->slice(1, 3) as $pick) {
        app(AddToLibrary::class)->handle($user, $pick, ReadingStatus::Completed);
    }

    $builder = app(ShelfBuilder::class);
    expect($builder->isCold($user))->toBeTrue();

    LibraryEntry::query()->where('user_id', $user->id)->update(['completed_at' => now()->subDays(5)]);
    app(AddToLibrary::class)->handle($user, $berserk);
    expect($builder->isCold($user))->toBeTrue();

    app(LogProgress::class)->handle($user, $berserk, 20.0);
    app(RateMedia::class)->handle($user, $berserk, 9);
    Cache::flush();

    expect($builder->isCold($user))->toBeFalse()
        ->and(homeShelves($user)->firstWhere('key', 'because-you-read')?->title)->toBe('Because You Read '.$berserk->title);
});

test('views, searches and low ratings are not strong interactions', function () {
    $catalog = homeCatalog();
    $user = User::factory()->create();
    // A seed title that counts for taste but is no strong interaction: the weak events below must not add up to one.
    libraryEntry($user, $catalog[7], 'reading');

    foreach ($catalog->take(6) as $story) {
        foreach ([InteractionEvent::View, InteractionEvent::Click, InteractionEvent::Search, InteractionEvent::ReadingProgress] as $event) {
            UserInteraction::factory()->create(['user_id' => $user->id, 'media_id' => $story->id, 'event' => $event]);
        }
        UserInteraction::factory()->create(['user_id' => $user->id, 'media_id' => $story->id, 'event' => InteractionEvent::Rating, 'value' => 5]);
    }

    expect(app(ShelfBuilder::class)->isCold($user))->toBeTrue();
});

test('a Recommended tile from a neighbor the pool cut off keeps the id of the title its label names', function () {
    $catalog = homeCatalog();
    $user = User::factory()->create();
    $lsa = activeVersion('lsa');
    $crowd = Media::factory()->count(130)->create(['published_on' => now()->subYears(3), 'popularity_score' => 0.01]);
    $fresh = Media::factory()->count(12)->create(['published_on' => now()->subDays(100), 'popularity_score' => 0, 'ratings_count' => 0, 'average_rating' => 0]);
    // The exploration draw is random and may pick these too, with a label of its own: fix it.
    app()->bind(Randomizer::class, fn () => new Randomizer(new Mt19937(1)));

    foreach ($catalog->slice(6, 2) as $seed) {
        libraryEntry($user, $seed, 'reading', favorite: true);
        foreach ($crowd as $j => $neighbor) {
            similar($seed, $neighbor, 'lsa', 0.25 - $j * 0.0005, $lsa);
        }
    }
    libraryEntry($user, $catalog[0], 'completed')->update(['completed_at' => now()]);
    foreach ($fresh as $j => $neighbor) {
        similar($catalog[0], $neighbor, 'lsa', 0.2 - $j * 0.001, $lsa);
    }
    warm($user, $catalog->take(5));
    config(['bookflix.recommendations.epsilon' => 0]);

    $pool = app(CandidatePool::class);
    $pooled = $pool->combine($user, $pool->bySource($user, 100))->pluck('mediaId');
    $cut = $fresh->pluck('id')->diff($pooled);
    // The exploration candidates have a label of their own; the neighbors carry the label of the title they came from.
    $tiles = homeShelves($user)->firstWhere('key', 'recommended')->items->filter(fn ($tile) => $cut->contains($tile->id) && $tile->reason_label !== 'Something different for you');

    expect($cut)->not->toBeEmpty()
        ->and($tiles)->not->toBeEmpty()
        ->and($tiles->every(fn ($tile) => $tile->reason_label === 'Because you read '.$catalog[0]->title && $tile->reason_media_id === $catalog[0]->id))->toBeTrue();
});

test('strong interactions on deleted or merged titles do not count', function () {
    $catalog = homeCatalog();
    $user = User::factory()->create();
    warm($user, $catalog->take(5));
    libraryEntry($user, $catalog[4], 'completed');
    expect(app(ShelfBuilder::class)->isCold($user))->toBeFalse();

    $catalog[0]->delete();
    $catalog[1]->update(['merged_into_id' => $catalog[2]->id]);

    expect(app(ShelfBuilder::class)->isCold($user))->toBeTrue();
});

test('a reader with enough interactions but no seed title is cold', function () {
    $catalog = homeCatalog();
    $user = User::factory()->create();
    warm($user, $catalog->take(5));

    expect(app(ShelfBuilder::class)->isCold($user))->toBeTrue();

    libraryEntry($user, $catalog[0], 'completed');
    expect(app(ShelfBuilder::class)->isCold($user))->toBeFalse();
});

test('shelf "Similar To {Y}" prefers embedding neighbors when present', function () {
    [$user, $catalog] = richReader();
    $embedding = activeVersion('embedding');
    $lsaNeighbors = DB::table('media_similarities')->where('media_id', $catalog[7]->id)->where('method', 'lsa')->pluck('similar_media_id');
    $semantic = Media::factory()->count(40)->create(['published_on' => now()->subYears(2), 'popularity_score' => 0]);
    $mature = matureStory('Forbidden Fruit');
    similar($catalog[7], $mature, 'embedding', 0.99, $embedding);
    foreach ($semantic as $j => $neighbor) {
        similar($catalog[7], $neighbor, 'embedding', 0.95 - $j * 0.01, $embedding);
    }

    $shelves = homeShelves($user);
    $similarTo = $shelves->firstWhere('key', 'similar-to');

    expect($similarTo->title)->toBe('Similar To '.$catalog[7]->title)
        ->and($similarTo->items)->not->toBeEmpty()
        ->and($similarTo->items->pluck('id')->diff($semantic->pluck('id'))->all())->toBe([])
        ->and($similarTo->items->pluck('id')->intersect($lsaNeighbors)->all())->toBe([])
        ->and($similarTo->items->first()->reason_label)->toBe('Similar themes to '.$catalog[7]->title)
        // A seed with no embedding rows keeps its lsa neighbors.
        ->and(shelfIds($shelves, 'because-you-read'))->not->toBeEmpty()
        ->and(collect(shelfIds($shelves, 'because-you-read'))->intersect($semantic->pluck('id'))->all())->toBe([])
        ->and($shelves->flatMap(fn ($shelf) => $shelf->items->pluck('id')))->not->toContain($mature->id);
});

test('with no active embedding version, or one without rows, every shelf is what the Phase 6 sources give', function () {
    [$user] = richReader();
    $build = function () use ($user): array {
        Cache::flush();
        app()->bind(Randomizer::class, fn () => new Randomizer(new Mt19937(3)));

        return app()->make(ShelfBuilder::class)->forHome($user)
            ->map(fn ($shelf) => [$shelf->key, $shelf->title, $shelf->items->map(fn ($m) => [$m->id, $m->reason_label, $m->reason_media_id])->all()])
            ->all();
    };

    $now = $build();

    $phase6 = collect(app()->tagged(RecommendationServiceProvider::SOURCES))->reject(fn ($source) => $source instanceof SemanticSimilarSource)->values()->all();
    app()->instance(CandidatePool::class, new CandidatePool($phase6));
    $before = $build();
    app()->forgetInstance(CandidatePool::class);

    activeVersion('embedding');
    $emptyVersion = $build();

    expect($now)->toBe($before)->and($emptyVersion)->toBe($before)
        ->and(collect($now)->pluck(0)->all())->toContain('recommended', 'because-you-read', 'similar-to');
});
