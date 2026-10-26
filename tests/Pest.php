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
use App\Models\DeweyClass;
use App\Models\ExternalSource;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use App\Models\MediaType;
use Database\Seeders\MediaTypeSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;
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

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
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
 * A hybrid search with no signed-in reader; filters default to none.
 *
 * @return LengthAwarePaginator<int, Media>
 */
function search(string $query, ?SearchFilters $filters = null): LengthAwarePaginator
{
    return app(HybridSearch::class)->search($query, $filters ?? new SearchFilters, null);
}
