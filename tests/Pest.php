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
use App\Enums\ContentFormat;
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
use App\Models\ContentSource;
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
use Illuminate\Support\Facades\Storage;
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
 * The admin sidebar navigation of a rendered page, as its items: label, href, whether it is the current one, and the count beside it (or null).
 *
 * @return list<array{label: string, href: string, current: bool, count: ?string}>
 */
function adminNav(string $html): array
{
    preg_match('#<nav[^>]*aria-label="Admin sections".*?</nav>#s', $html, $nav);

    preg_match_all('#<a\b([^>]*)>(.*?)</a>#s', $nav[0] ?? '', $links, PREG_SET_ORDER);

    return array_map(function (array $link): array {
        preg_match('#href="([^"]*)"#', $link[1], $href);
        preg_match('#data-content[^>]*>(.*?)</div>#s', $link[2], $label);
        preg_match('#data-flux-navlist-badge>(.*?)</span>#s', $link[2], $count);

        return [
            'label' => trim(strip_tags($label[1] ?? '')),
            'href' => $href[1] ?? '',
            'current' => str_contains($link[1], 'aria-current="page"'),
            'count' => isset($count[1]) ? trim(strip_tags($count[1])) : null,
        ];
    }, $links);
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
 * Alpine's `@event` shorthand is not a valid attribute name for libxml 2.9 (still on some local machines; CI's is newer), which then
 * ends the tag at the first `>` inside the value. It is parsed as its `x-on:event` long form, so query `name()="x-on:keydown.escape"`.
 * libxml 2.9 also parses a void `<source>` as a container, so what follows it lands inside: query `//picture[.//source]//img`, which
 * holds on both parsers, not `//picture[source]/img`.
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
/**
 * A script for `assertScript($script, true)` that waits (polling every 50ms, at most 8 seconds) until the JavaScript condition holds and answers whether it
 * did: a condition wait in place of a fixed sleep, so a slow machine waits longer and a fast one does not wait at all.
 */
function browserUntil(string $condition): string
{
    return 'new Promise((resolve) => { const end = Date.now() + 8000; const check = () => { let ok = false; try { ok = !! ('.$condition.'); } catch (e) {} if (ok) { resolve(true); } else if (Date.now() > end) { resolve(false); } else { setTimeout(check, 50); } }; check(); })';
}

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
    collectionOf(User::factory()->create(), $media->slice(20, 6)->values()->all(), ['is_editorial' => true, 'name' => 'Staff Shelf']);
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

/**
 * A tiny valid EPUB 3 with two chapters ("Down the Rabbit-Hole" and "The Pool of Tears"): the stored `mimetype` first, `container.xml`, the package
 * document, a nav and one XHTML file per chapter, each with `$paragraphs` paragraphs so a chapter spans several pages. `$extraHtml` goes at the top of chapter 1.
 * `$images` (file name => PNG bytes) are stored next to the chapters and listed in the manifest, for `$extraHtml` to show.
 *
 * @param  array<string, string>  $images
 */
function tinyEpub(int $paragraphs = 30, string $extraHtml = '', array $images = []): string
{
    $imageItems = implode('', array_map(fn (string $name, int $n) => "<item id=\"img{$n}\" href=\"{$name}\" media-type=\"image/png\"/>", array_keys($images), array_keys(array_keys($images))));

    $zip = new ZipArchive;
    $temp = (string) tempnam(sys_get_temp_dir(), 'epub');
    $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $zip->addFromString('mimetype', 'application/epub+zip');
    $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
    $zip->addFromString('META-INF/container.xml', <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
          <rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles>
        </container>
        XML);
    $zip->addFromString('OEBPS/content.opf', <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="id">
          <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
            <dc:identifier id="id">urn:uuid:3f2b8c1e-0000-4000-8000-000000000001</dc:identifier>
            <dc:title>The Open Book</dc:title>
            <dc:language>en</dc:language>
            <meta property="dcterms:modified">2026-11-17T00:00:00Z</meta>
          </metadata>
          <manifest>
            <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
            <item id="c1" href="ch1.xhtml" media-type="application/xhtml+xml"/>
            <item id="c2" href="ch2.xhtml" media-type="application/xhtml+xml"/>
            {$imageItems}
          </manifest>
          <spine><itemref idref="c1"/><itemref idref="c2"/></spine>
        </package>
        XML);
    $zip->addFromString('OEBPS/nav.xhtml', <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops"><head><title>Contents</title></head>
        <body><nav epub:type="toc"><ol>
          <li><a href="ch1.xhtml">I. Down the Rabbit-Hole</a></li>
          <li><a href="ch2.xhtml">II. The Pool of Tears</a></li>
        </ol></nav></body></html>
        XML);

    $chapters = ['ch1.xhtml' => ['Chapter I.', 'Down the Rabbit-Hole', 'Alice was beginning to get very tired of sitting by her sister on the bank, and of having nothing to do: once or twice she had peeped into the book her sister was reading.'], 'ch2.xhtml' => ['Chapter II.', 'The Pool of Tears', 'Curiouser and curiouser, cried Alice, she was so much surprised that for the moment she quite forgot how to speak good English, and the garden grew very strange.']];

    foreach ($chapters as $file => [$eyebrow, $title, $sentence]) {
        $body = implode("\n", array_map(fn (int $n) => "<p>{$sentence} ({$n})</p>", range(1, $paragraphs)));

        $zip->addFromString("OEBPS/{$file}", "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<html xmlns=\"http://www.w3.org/1999/xhtml\"><head><title>{$title}</title></head><body><h1>{$title}</h1><p>{$eyebrow}</p>".($file === 'ch1.xhtml' ? $extraHtml : '')."{$body}</body></html>");
    }

    foreach ($images as $name => $png) {
        $zip->addFromString("OEBPS/{$name}", $png);
    }

    // A zip normally ends in NUL bytes, which the browser-test server's `mb_trim` of a streamed body would cut off (and the Content-Length with them): a comment ends it in text.
    $zip->setArchiveComment('bookflix test fixture');
    $zip->close();
    $bytes = (string) file_get_contents($temp);
    unlink($temp);

    return $bytes;
}

/**
 * A verified public-domain EPUB source whose file is `$bytes` (the tiny book by default), on a fresh story, on the faked reader disk.
 */
function epubSource(?string $bytes = null): ContentSource
{
    Storage::fake(ContentSource::disk());

    $bytes ??= tinyEpub();
    $source = ContentSource::factory()->for(Media::factory()->novel()->create(['title' => 'The Open Book']))->publicDomain()->verified()->ready()->create();
    Storage::disk(ContentSource::disk())->put($source->storage_path, $bytes);
    $source->update(['byte_size' => strlen($bytes)]);

    return $source;
}

/**
 * A small valid PDF by hand: `$pages` Letter pages, each saying "Page n of N" in Helvetica, a correct xref table, and no newline after the final
 * `%%EOF` (the browser-test server trims trailing whitespace from a streamed body, and the Content-Length with it). With `$encrypted` the
 * trailer has an /Encrypt dictionary (standard handler, revision 2) whose user entry matches no password, so opening it asks for one.
 */
function tinyPdf(int $pages = 3, bool $encrypted = false, array $outline = [], bool $blank = false): string
{
    $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    $kids = [];

    for ($n = 1; $n <= $pages; $n++) {
        $page = 4 + ($n - 1) * 2;
        $text = $blank ? '0 0 10 10 re f' : "BT /F1 36 Tf 72 700 Td (Page {$n} of {$pages}) Tj ET";
        $kids[] = "{$page} 0 R";
        $objects[$page] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents '.($page + 1).' 0 R >>';
        $objects[$page + 1] = '<< /Length '.strlen($text)." >>\nstream\n{$text}\nendstream";
    }

    $objects[2] = '<< /Type /Pages /Count '.$pages.' /Kids ['.implode(' ', $kids).'] >>';
    $encrypt = null;

    // An outline (title => page, in order): the root, then one item per entry, each pointing at its page with an explicit destination.
    if ($outline !== []) {
        $root = 4 + $pages * 2 + ($encrypted ? 1 : 0);
        $items = [];
        $n = 0;

        foreach ($outline as $title => $target) {
            $id = $root + 1 + $n;
            $items[] = $id;
            $links = ($n > 0 ? '/Prev '.($id - 1).' 0 R ' : '').($n < count($outline) - 1 ? '/Next '.($id + 1).' 0 R ' : '');
            $objects[$id] = '<< /Title ('.$title.') /Parent '.$root.' 0 R '.$links.'/Dest ['.(4 + ($target - 1) * 2).' 0 R /Fit] >>';
            $n++;
        }

        $objects[$root] = '<< /Type /Outlines /First '.$items[0].' 0 R /Last '.end($items).' 0 R /Count '.count($items).' >>';
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R /Outlines '.$root.' 0 R >>';
    }

    if ($encrypted) {
        $encrypt = 4 + $pages * 2;
        $objects[$encrypt] = '<< /Filter /Standard /V 1 /R 2 /P -4 /O <'.str_repeat('ab', 32).'> /U <'.str_repeat('cd', 32).'> >>';
    }

    ksort($objects);

    // The test server trims trailing whitespace from every streamed body, a range of a file too: a range of 64 KB must not end in whitespace.
    // A comment line ahead of the text that would end one moves it by a byte, until none does.
    $ends = fn (string $text, int $from): bool => (function () use ($text, $from): bool {
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            if (($from + $i + 1) % 65536 === 0 && strpbrk($text[$i], " \n\r\t\0\f\v") !== false) {
                return true;
            }
        }

        return false;
    })();
    $body = "%PDF-1.4\n";
    $offsets = [];

    foreach ($objects as $number => $object) {
        $text = "{$number} 0 obj\n{$object}\nendobj\n";

        for ($shift = 0; $ends(str_repeat('%', $shift).($shift > 0 ? "\n" : '').$text, strlen($body)); $shift++);

        $body .= ($shift > 0 ? str_repeat('%', $shift)."\n" : '');
        $offsets[$number] = strlen($body);
        $body .= $text;
    }

    $trailer = '/Size '.(count($objects) + 1).' /Root 1 0 R'.($encrypt === null ? '' : " /Encrypt {$encrypt} 0 R /ID [<".str_repeat('01', 16).'> <'.str_repeat('01', 16).'>]');
    $table = "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n".implode('', array_map(fn (int $offset) => sprintf("%010d 00000 n \n", $offset), $offsets))."trailer\n<< {$trailer} >>\n";

    for ($shift = 0; ; $shift++) {
        $pad = $shift > 0 ? str_repeat('%', $shift)."\n" : '';
        $tail = $pad.$table."startxref\n".(strlen($body) + strlen($pad))."\n%%EOF";

        if (! $ends($tail, strlen($body))) {
            return $body.$tail;
        }
    }
}

/**
 * A verified public-domain PDF source whose file is `$bytes` (the 3-page one by default), on a fresh story, on the faked reader disk.
 */
function pdfSource(?string $bytes = null): ContentSource
{
    Storage::fake(ContentSource::disk());

    $bytes ??= tinyPdf();
    $source = ContentSource::factory()->for(Media::factory()->novel()->create(['title' => 'The Open Page']))->publicDomain()->verified()->ready()->create(['format' => ContentFormat::Pdf, 'mime_type' => 'application/pdf']);
    Storage::disk(ContentSource::disk())->put($source->storage_path, $bytes);
    $source->update(['byte_size' => strlen($bytes)]);

    return $source;
}

/**
 * A verified public-domain comic source (CBZ of `$pages` small pages, `$size` pixels each unless `$sizes` says otherwise for a page) on a fresh story
 * of `$type` (`manga` from Japan reads right to left; `webcomic` is a vertical strip), on the faked reader disk.
 *
 * @param  array{0: int, 1: int}  $size
 * @param  array<int, array{0: int, 1: int}>  $sizes
 */
function comicSource(string $type = 'manga', int $pages = 5, array $size = [40, 60], array $sizes = [], ?string $title = null): ContentSource
{
    Storage::fake(ContentSource::disk());

    $media = $type === 'manga'
        ? Media::factory()->manga()->create(['title' => $title ?? 'Vagabond Tides', 'country_of_origin' => 'JP'])
        : Media::factory()->ofType($type)->create(['title' => $title ?? 'Harbour Lights', 'country_of_origin' => 'KR']);

    return ContentSource::factory()->for($media)->publicDomain()->verified()->cbz($pages, $size, $sizes)->create();
}
