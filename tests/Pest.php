<?php

use App\Catalog\Data\NormalizedCreator;
use App\Catalog\Data\NormalizedMedia;
use App\Catalog\Data\ProviderRecord;
use App\Catalog\Ingestion\IngestionPipeline;
use App\Classification\ClassificationCandidate;
use App\Discovery\Search\HybridSearch;
use App\Discovery\Search\SearchFilters;
use App\Enums\ClassificationMethod;
use App\Enums\ClassificationType;
use App\Enums\CreatorRole;
use App\Enums\ImportOutcome;
use App\Enums\ReviewStatus;
use App\Models\Creator;
use App\Models\DeweyClass;
use App\Models\ExternalSource;
use App\Models\Genre;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use App\Models\MediaExternalId;
use App\Models\MediaType;
use App\Models\Publisher;
use App\Models\Series;
use App\Models\Tag;
use App\Models\Theme;
use Database\Seeders\DeweySeeder;
use Database\Seeders\MediaTypeSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
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
 */
function normalized(string $title, string $type = 'novel', ?int $year = null, array $creators = [], array $isbns = [], array $crossIds = [], string $provider = 'test', ?string $description = null, ?string $deweyCode = null): NormalizedMedia
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
        'content_warnings' => ['Violence'],
        'average_rating' => '9.0',
        'ratings_count' => 120,
    ]);

    $media->creators()->attach(Creator::factory()->create(['name' => 'Frank Herbert']), ['role' => CreatorRole::Author->value, 'position' => 0]);
    $media->publishers()->attach(Publisher::factory()->create(['name' => 'Chilton Books']));
    $media->genres()->attach(Genre::factory()->create(['name' => 'Science Fiction', 'slug' => 'science-fiction']));
    $media->themes()->attach(Theme::factory()->create(['name' => 'Politics', 'slug' => 'politics']));
    $media->tags()->attach(Tag::factory()->create(['name' => 'Desert']));

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
