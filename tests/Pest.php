<?php

use App\Catalog\Data\NormalizedCreator;
use App\Catalog\Data\NormalizedMedia;
use App\Catalog\Data\ProviderRecord;
use App\Catalog\Ingestion\IngestionPipeline;
use App\Classification\ClassificationCandidate;
use App\Discovery\Recommendations\Explanation;
use App\Discovery\Recommendations\RankedItem;
use App\Discovery\Search\HybridSearch;
use App\Discovery\Search\SearchFilters;
use App\Enums\ClassificationMethod;
use App\Enums\ClassificationType;
use App\Enums\CreatorRole;
use App\Enums\ImportOutcome;
use App\Enums\InteractionEvent;
use App\Enums\ModelKind;
use App\Enums\ModelStatus;
use App\Enums\ReadingStatus;
use App\Enums\ReasonType;
use App\Enums\RecommendationEventType;
use App\Enums\ReviewStatus;
use App\Models\Collection as StoryCollection;
use App\Models\CollectionItem;
use App\Models\ContentWarning;
use App\Models\Creator;
use App\Models\DeweyClass;
use App\Models\ExternalSource;
use App\Models\Genre;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use App\Models\MediaExternalId;
use App\Models\MediaSimilarity;
use App\Models\MediaType;
use App\Models\ModelVersion;
use App\Models\Publisher;
use App\Models\Recommendation;
use App\Models\RecommendationEvent;
use App\Models\Series;
use App\Models\Tag;
use App\Models\Theme;
use App\Models\User;
use App\Models\UserInteraction;
use Database\Seeders\DeweySeeder;
use Database\Seeders\MediaTypeSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\Webpage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Feature');

// Browser tests run against the built assets (`npm run build`), as CI does, even while a dev server's `public/hot` file exists.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => Vite::useHotFile(storage_path('framework/vite.hot')))
    ->in('Browser');

// A whole page with its lazy shelves can take over the default 5s to load on a busy CI runner.
pest()->browser()->timeout(10_000);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * The desktop header navbar and the mobile bottom nav markup of a rendered page.
 *
 * @return array{header: string, bottom: string}
 */
function primaryNavs(string $html): array
{
    preg_match('#<nav[^>]*data-flux-navbar>.*?</nav>#s', $html, $header);
    preg_match('#<nav aria-label="Primary".*?</nav>#s', $html, $bottom);

    return ['header' => $header[0] ?? '', 'bottom' => $bottom[0] ?? ''];
}

/**
 * A response the ML service and Laravel agree on (`tests/Fixtures/ml-contracts/`); the Python tests check the same files.
 *
 * @return array<string, mixed>
 */
function contract(string $name): array
{
    return json_decode(file_get_contents(__DIR__."/Fixtures/ml-contracts/{$name}"), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * A provider fixture recorded from the real API (Pest already owns `fixture()`, which returns a path), e.g. `openlibrary/work-dune.json`.
 *
 * @return array<string, mixed>
 */
function providerFixture(string $path): array
{
    return json_decode(file_get_contents(__DIR__."/Fixtures/providers/{$path}"), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * A NormalizedMedia with sensible defaults. The default `novel` type shares a family with the media factory's default type.
 * `provider` only labels the source URL.
 *
 * @param  list<string>  $creators
 * @param  list<string>  $isbns
 * @param  array<string, string>  $crossIds
 * @param  array<string, int|float>  $metrics
 */
function normalized(string $title, string $type = 'novel', ?int $year = null, array $creators = [], array $isbns = [], array $crossIds = [], string $provider = 'test', ?string $description = null, ?string $deweyCode = null, array $metrics = []): NormalizedMedia
{
    if (isset(MediaTypeSeeder::TYPES[$type])) {
        MediaType::firstOrCreate(['slug' => $type], MediaTypeSeeder::TYPES[$type]);
    }

    return new NormalizedMedia(
        title: $title,
        mediaTypeSlug: $type,
        creators: array_map(fn (string $name) => new NormalizedCreator($name, CreatorRole::Author), $creators),
        isbns: $isbns,
        crossIds: $crossIds,
        description: $description,
        publicationYear: $year,
        externalDeweyCode: $deweyCode,
        sourceUrl: "https://{$provider}.test/".rawurlencode($title),
        metrics: $metrics,
    );
}

/**
 * Import a recorded provider payload through the pipeline, with `mutate` deep-merged over it first. Creates the media types and the provider's source row when missing.
 *
 * @param  array<string, mixed>  $mutate
 */
function importFixture(string $provider, string $fixture, array $mutate = []): ImportOutcome
{
    app(MediaTypeSeeder::class)->run();
    ExternalSource::where('key', $provider)->exists() || ExternalSource::factory()->create(['key' => $provider]);

    $payload = array_replace_recursive(providerFixture("{$provider}/{$fixture}.json"), $mutate);
    $record = new ProviderRecord($provider, (string) ($payload['id'] ?? ''), $payload, $payload['siteUrl'] ?? null, now()->toImmutable());

    return app(IngestionPipeline::class)->import($record, app(config("bookflix.providers.{$provider}.class")));
}

/**
 * A classification candidate for a code; the source follows the method the way production sources do.
 */
function cand(string $code, float $confidence, ClassificationMethod $method = ClassificationMethod::Automatic): ClassificationCandidate
{
    $source = match ($method) {
        ClassificationMethod::Manual => 'admin',
        ClassificationMethod::External => 'open_library',
        ClassificationMethod::Automatic => 'rules',
    };

    return new ClassificationCandidate($code, $confidence, $method, $source);
}

/**
 * A seeded Dewey-inspired class by code; seed `DeweySeeder` first.
 */
function dewey(string $code): DeweyClass
{
    return DeweyClass::where('code', $code)->firstOrFail();
}

/**
 * Give each media a class with this code (an accepted primary by default); seed `DeweySeeder` first. Returns what it was given.
 *
 * @param  Media|Collection<int, Media>  $media
 * @return ($media is Media ? Media : Collection<int, Media>)
 */
function classified(Media|Collection $media, string $code, string $type = 'primary', string $status = 'accepted', float $confidence = 0.95): Media|Collection
{
    foreach (Collection::wrap($media) as $item) {
        MediaDeweyClass::factory()->for($item)->create([
            'dewey_class_id' => dewey($code)->id,
            'classification_type' => ClassificationType::from($type),
            'review_status' => ReviewStatus::from($status),
            'confidence' => $confidence,
        ]);
    }

    return $media;
}

/**
 * A story with everything the page can show: creators, publisher, genres, themes, tags, a series of three, a related story,
 * content warnings, a public class (813) and a provider source with attribution.
 */
function fullMedia(): Media
{
    (new DeweySeeder)->run();

    $media = Media::factory()->novel()->create([
        'title' => 'Dune',
        'alternative_titles' => ['Duna'],
        'description' => 'A desert planet and the spice that rules it.',
        'average_rating' => '4.5',
        'ratings_count' => 120,
    ]);

    $media->creators()->attach(Creator::factory()->create(['name' => 'Frank Herbert']), ['role' => CreatorRole::Author->value, 'position' => 0]);
    $media->publishers()->attach(Publisher::factory()->create(['name' => 'Chilton Books']));
    $media->genres()->attach(Genre::factory()->create(['name' => 'Science Fiction', 'slug' => 'science-fiction']));
    $media->themes()->attach(Theme::factory()->create(['name' => 'Politics', 'slug' => 'politics']));
    $media->tags()->attach(Tag::factory()->create(['name' => 'Desert']));
    $media->warnings()->attach(ContentWarning::factory()->create(['name' => 'Violence', 'slug' => 'violence']), ['status' => 'present', 'source' => 'test']);

    $series = Series::factory()->create(['title' => 'Dune Chronicles']);
    $media->series()->attach($series, ['position' => 1]);
    Media::factory()->create(['title' => 'Dune Messiah'])->series()->attach($series, ['position' => 2]);

    $media->relations()->attach(Media::factory()->create(['title' => 'Dune: The Movie']), ['relation' => 'adaptation']);

    classified($media, '813');

    $source = ExternalSource::factory()->create(['attribution' => 'Book data from Open Library (openlibrary.org).']);
    MediaExternalId::factory()->create(['media_id' => $media->id, 'external_source_id' => $source->id]);

    return $media;
}

/**
 * A hybrid search with no signed-in reader; filters default to none.
 *
 * @return LengthAwarePaginator<int, Media>
 */
function search(string $query, ?SearchFilters $filters = null): LengthAwarePaginator
{
    return app(HybridSearch::class)->search($query, $filters ?? new SearchFilters, null);
}

/**
 * The names of the interactions recorded so far, oldest first.
 *
 * @return list<string>
 */
function interactionEvents(): array
{
    return UserInteraction::orderBy('id')->get()->map(fn (UserInteraction $row): string => $row->event->value)->all();
}

/**
 * Make the default queue one that only notes what it is given, and that holds a job flagged `afterCommit` back until the open transaction commits
 * (and forgets it on a rollback), as a real driver does. The sync queue runs a job at once and Queue::fake ignores the flag, so neither can show it.
 * `$queue->pushed` lists the class of each job that reached the queue, in order.
 */
function recordingQueue(): SyncQueue
{
    $queue = new class extends SyncQueue
    {
        /** @var list<class-string> */
        public array $pushed = [];

        public function push($job, $data = '', $queue = null)
        {
            return $this->enqueueUsing($job, $this->createPayload($job, $queue, $data), $queue, null, function () use ($job): int {
                $this->pushed[] = $job::class;

                return 0;
            });
        }
    };

    $queue->setContainer(app());
    $queue->setConnectionName('recording');

    app('queue')->addConnector('recording', fn (): ConnectorInterface => new class($queue) implements ConnectorInterface
    {
        public function __construct(private readonly SyncQueue $queue) {}

        public function connect(array $config): SyncQueue
        {
            return $this->queue;
        }
    });
    config(['queue.connections.recording' => ['driver' => 'recording'], 'queue.default' => 'recording']);

    return $queue;
}

/**
 * A story behind the mature gate: tagged with a mature family genre.
 */
function matureStory(string $title = 'Erotic Tale'): Media
{
    $media = Media::factory()->create(['title' => $title]);
    $media->genres()->attach(Genre::factory()->mature()->create());

    return $media;
}

/**
 * What the readers' aggregate (the synthetic `bookflix` source) holds for one metric of this story, or null when it holds none.
 */
function bookflixMetric(Media $media, string $metric): ?float
{
    $value = DB::table('media_metrics')
        ->where('media_id', $media->id)
        ->where('metric', $metric)
        ->whereIn('external_source_id', ExternalSource::where('key', 'bookflix')->select('id'))
        ->value('value');

    return $value === null ? null : (float) $value;
}

/**
 * Give this story a provider's metrics (`ratings_count`, `average_rating` on the 0-5 scale, and so on), creating the provider's source when missing.
 * The story's own rating columns are not touched: run `MediaRatings::blend` or the aggregate job to fold them in.
 *
 * @param  array<string, int|float>  $metrics
 */
function providerMetrics(Media $media, string $provider, array $metrics): ExternalSource
{
    $source = ExternalSource::where('key', $provider)->first() ?? ExternalSource::factory()->create(['key' => $provider]);

    foreach ($metrics as $metric => $value) {
        DB::table('media_metrics')->insert(['media_id' => $media->id, 'external_source_id' => $source->id, 'metric' => $metric, 'value' => $value, 'observed_at' => now()]);
    }

    return $source;
}

/**
 * A collection of these stories in this order, owned by this reader.
 *
 * @param  list<Media>  $stories
 * @param  array<string, mixed>  $attributes
 */
function collectionOf(User $owner, array $stories, array $attributes = []): StoryCollection
{
    $collection = StoryCollection::factory()->for($owner)->create($attributes);

    foreach ($stories as $position => $story) {
        CollectionItem::factory()->for($collection)->for($story)->create(['position' => $position]);
    }

    return $collection;
}

/**
 * Stories with a cover and a falling popularity score ("Popular Tale 1" is the most popular), for the landing page. `$cover` is the cover
 * url (`{n}` becomes the story's number); the default is a local file so a browser test needs no network.
 *
 * @param  array<string, mixed>  $overrides
 * @return list<Media>
 */
function popularStories(int $count, array $overrides = [], string $cover = '/favicon.svg'): array
{
    return Media::factory()->count($count)->sequence(fn ($sequence) => [
        'title' => 'Popular Tale '.($sequence->index + 1),
        'cover_url' => str_replace('{n}', (string) ($sequence->index + 1), $cover),
        'popularity_score' => 1 - ($sequence->index / 100),
        ...$overrides,
    ])->create()->all();
}

/**
 * Component acceptance harness, feature side: render Blade markup (`<x-cover-tile :media="$media" />`) to HTML.
 *
 * @param  array<string, mixed>  $data
 */
function renderedComponent(string $markup, array $data = []): string
{
    return Blade::render($markup, $data);
}

/**
 * Component acceptance harness, feature side: query rendered HTML with XPath, e.g. `htmlQuery($html, '//img[@loading="lazy"]')->length`.
 *
 * Alpine's `@event` shorthand is not a valid attribute name for libxml 2.9 (CI), which then ends the tag at the
 * first `>` inside the value. It is parsed as its `x-on:event` long form, so query `name()="x-on:keydown.escape"`.
 */
function htmlQuery(string $html, string $xpath): DOMNodeList
{
    $dom = new DOMDocument;

    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?><body>'.preg_replace('/(\s)@([a-zA-Z][\w.:-]*)(?==)/', '$1x-on:$2', $html).'</body>');
    libxml_clear_errors();

    return (new DOMXPath($dom))->query($xpath);
}

/**
 * Component acceptance harness, browser side: serve Blade markup as a test-only page with the real
 * assets, Livewire and Alpine, and return its path. The page links to a second page that links back,
 * so a test proves the component survives a `wire:navigate` round trip (Phase 4 Review Focus #5).
 *
 * @param  array<string, mixed>  $data
 */
function componentPage(string $markup, array $data = []): string
{
    View::addLocation(base_path('tests/Fixtures/views'));

    Route::middleware('web')->group(function () use ($markup, $data) {
        Route::get('/_test/component', fn () => view('component-fixture', ['markup' => $markup, 'data' => $data]));
        Route::get('/_test/elsewhere', fn () => view('component-fixture', [
            'markup' => '<p>Elsewhere page</p><a href="/_test/component" wire:navigate>Back to component</a>',
            'data' => [],
        ]));
    });

    return '/_test/component';
}

/**
 * The browser checks every component shares: no JavaScript errors, no serious axe violations, and
 * `$interact` (the component's own keyboard pattern) working both on first load and after a `wire:navigate` round trip.
 * Pass `['reducedMotion' => 'reduce']` as `$options` to run the same checks with motion reduced.
 *
 * @param  array<string, mixed>  $options
 */
function assertComponentAcceptance(string $path, ?Closure $interact = null, array $options = []): void
{
    // The longest entrance (reveal stagger + fade) ends well inside a second; axe reads contrast mid-fade otherwise.
    $page = visit($path, $options)->wait(1)->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();

    $interact?->__invoke($page);

    $page->click('Go elsewhere')->assertSee('Elsewhere page');
    $page->click('Back to component')->wait(1)->assertNoJavaScriptErrors();

    $interact?->__invoke($page);
}

/**
 * Scroll a browser page top to bottom in viewport steps (so lazy shelves load and reveals run), then back to the top.
 *
 * @param  AwaitableWebpage|Webpage  $page
 */
function scrolledThrough($page): void
{
    for ($step = 1; $step <= 8; $step++) {
        $page->script("window.scrollTo(0, window.innerHeight * {$step})");
        $page->wait(0.7);

        if ($page->script("window.innerHeight * {$step} >= document.documentElement.scrollHeight")) {
            break;
        }
    }

    $page->script('window.scrollTo(0, 0)');
    $page->wait(0.3);
}

/**
 * The active version of a model by name (`lsa`, `item-cf`), created when missing; its kind is the name with an underscore.
 */
function activeVersion(string $name): ModelVersion
{
    return ModelVersion::activeFor($name) ?? ModelVersion::factory()->create([
        'name' => $name,
        'kind' => ModelKind::from(str_replace('-', '_', $name)),
        'status' => ModelStatus::Active,
    ]);
}

/**
 * One stored neighbor of a media under a method, as the version wrote it.
 */
function similar(Media $media, Media $neighbor, string $method, float $score, ModelVersion $version): MediaSimilarity
{
    return MediaSimilarity::create([
        'media_id' => $media->id,
        'similar_media_id' => $neighbor->id,
        'method' => $method,
        'score' => $score,
        'model_version_id' => $version->id,
    ]);
}

/**
 * A story in a reader's library at a status (a status name or case), optionally a favorite.
 */
function libraryEntry(User $user, Media $media, ReadingStatus|string $status = 'want_to_read', bool $favorite = false): LibraryEntry
{
    $status = $status instanceof ReadingStatus ? $status : ReadingStatus::from($status);

    return LibraryEntry::factory()->status($status)->for($user)->for($media)->create(['favorited_at' => $favorite ? now() : null]);
}

/**
 * A recommendation of this story the reader dismissed.
 */
function dismissed(User $user, Media $media): Recommendation
{
    $recommendation = Recommendation::factory()->create(['user_id' => $user->id, 'media_id' => $media->id]);
    RecommendationEvent::factory()->create(['recommendation_id' => $recommendation->id, 'event' => RecommendationEventType::Dismiss]);

    return $recommendation;
}

/**
 * A ranked item with the given score, for the diversifier and injector tests.
 */
function ranked(int $mediaId, float $score): RankedItem
{
    return new RankedItem($mediaId, $score, [], new Explanation(ReasonType::Trending));
}

/**
 * Forty stories for the home shelves: fifteen released in the last two months, all well rated, in three genres (the last ten Horror, so exploration has something outside the seeds' taste), ten classified, with popularity
 * refreshed and one staff-pick collection. Returns the stories in id order.
 *
 * @return Collection<int, Media>
 */
function homeCatalog(): Collection
{
    test()->seed(DeweySeeder::class);
    [$fantasy, $mystery, $horror] = Genre::factory()->count(3)->sequence(['name' => 'Fantasy'], ['name' => 'Mystery'], ['name' => 'Horror'])->create();
    $media = Media::factory()->count(40)->sequence(fn ($sequence) => [
        'published_on' => $sequence->index < 15 ? now()->subDays(3 + $sequence->index * 3) : now()->subYears(2),
        'ratings_count' => 10 + $sequence->index,
        'average_rating' => 4.2,
        'popularity_score' => 1 - $sequence->index / 50,
    ])->create();
    $media->each(fn (Media $story, int $i) => $story->genres()->attach($i >= 30 ? $horror : ($i % 2 === 0 ? $fantasy : $mystery)));
    classified($media->take(10), '813');
    collectionOf(User::factory()->create(), $media->slice(20, 6)->values()->all(), ['is_editorial' => true, 'visibility' => 'public', 'name' => 'Staff Shelf']);
    DB::statement('refresh materialized view media_popularity');
    activeVersion('hybrid-weights');

    return $media;
}

/**
 * The library adds these stories would have recorded: the interactions, not the library, are what end a reader's cold start.
 *
 * @param  iterable<int, Media>  $stories
 */
function warm(User $user, iterable $stories): void
{
    foreach ($stories as $story) {
        UserInteraction::factory()->create(['user_id' => $user->id, 'media_id' => $story->id, 'event' => InteractionEvent::LibraryAdd]);
    }
}

/**
 * A reader with eight seeds from the catalog (six completed, the first of them the latest; two read and favorited) and text neighbors of each
 * seed among the other stories, so every personalized shelf has something.
 *
 * @return array{User, Collection<int, Media>}
 */
function richReader(): array
{
    $catalog = homeCatalog();
    $user = User::factory()->create();
    $lsa = activeVersion('lsa');
    // Enough recent, popular stories that each candidate shelf can fill after the titles of the shelves before it are taken out.
    $crowd = Media::factory()->count(150)->sequence(fn ($sequence) => [
        // Every third is an older, quiet Horror story: nothing but Discover Something New offers it, so that shelf has enough of its own.
        'published_on' => $sequence->index % 3 === 2 ? now()->subYears(3) : now()->subDays(1 + $sequence->index),
        'ratings_count' => 50 + $sequence->index,
        'average_rating' => 4.0,
        'popularity_score' => $sequence->index % 3 === 2 ? 0.001 : 0.3 - $sequence->index / 600,
    ])->create();
    $crowd->each(fn (Media $story, int $i) => $story->genres()->attach(Genre::where('name', ['Fantasy', 'Mystery', 'Horror'][$i % 3])->first()));
    DB::statement('refresh materialized view media_popularity');

    foreach ($catalog->take(6) as $i => $seed) {
        libraryEntry($user, $seed, 'completed')->update(['completed_at' => now()->subDays($i)]);
    }

    foreach ($catalog->slice(6, 2) as $seed) {
        libraryEntry($user, $seed, 'reading', favorite: true);
    }

    warm($user, $catalog->take(8));

    foreach ($catalog->take(8) as $seed) {
        foreach ($catalog->slice(8 + $seed->id % 3, 24) as $j => $neighbor) {
            similar($seed, $neighbor, 'lsa', max(0.05, 0.95 - $j * 0.03), $lsa);
        }
        foreach ($crowd->slice(($seed->id % 4) * 30, 40) as $j => $neighbor) {
            similar($seed, $neighbor, 'lsa', max(0.05, 0.9 - $j * 0.02), $lsa);
        }
    }

    return [$user, $catalog];
}
