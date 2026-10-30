<?php

use App\Catalog\Data\ProviderRecord;
use App\Catalog\Ingestion\IngestionPipeline;
use App\Catalog\Providers\ComicVine\ComicVineProvider;
use App\Catalog\Providers\MissingProviderCredentials;
use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Enums\IngestionMode;
use App\Enums\IngestionStatus;
use App\Jobs\FetchProviderPage;
use App\Models\ExternalSource;
use App\Models\IngestionRun;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['bookflix.providers.comicvine.api_key' => 'secret-key']));

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function comicVineVolume(array $overrides = []): array
{
    return [
        'id' => 2127,
        'name' => 'Amazing Spider-Man',
        'aliases' => "ASM\nSpider-Man",
        'deck' => 'The wall-crawler.',
        'description' => '<p>Peter Parker <b>swings</b> &amp; fights.</p>',
        'image' => ['medium_url' => 'https://comicvine.gamespot.com/a/uploads/scale_medium/1/asm.jpg'],
        'publisher' => ['name' => 'Marvel'],
        'start_year' => '1963',
        'count_of_issues' => 900,
        'site_detail_url' => 'https://comicvine.gamespot.com/amazing-spider-man/4050-2127/',
        'date_last_updated' => '2026-10-01 10:00:00',
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $results
 */
function comicVineFake(array $results, int $total, int $status = 1, string $error = 'OK'): void
{
    Http::fake(['comicvine.gamespot.com/*' => Http::response(['error' => $error, 'status_code' => $status, 'number_of_total_results' => $total, 'results' => $results])]);
}

test('page maps a volume to a comic book with publisher, year, issues, urls and aliases', function () {
    comicVineFake([comicVineVolume()], 1);

    $page = app(ComicVineProvider::class)->page(null, null);
    $media = app(ComicVineProvider::class)->normalize($page->records[0]);

    expect($page->records[0]->externalId)->toBe('2127')
        ->and($media->title)->toBe('Amazing Spider-Man')
        ->and($media->mediaTypeSlug)->toBe('comic-book')
        ->and($media->alternativeTitles)->toBe(['ASM', 'Spider-Man'])
        ->and($media->description)->toBe('Peter Parker swings & fights.')
        ->and($media->publishers)->toBe(['Marvel'])
        ->and($media->publicationYear)->toBe(1963)
        ->and($media->chapterCount)->toBe(900)
        ->and($media->coverUrl)->toBe('https://comicvine.gamespot.com/a/uploads/scale_medium/1/asm.jpg')
        ->and($media->sourceUrl)->toBe('https://comicvine.gamespot.com/amazing-spider-man/4050-2127/')
        ->and($page->maxUpdatedAt->utc()->toDateTimeString())->toBe('2026-10-01 17:00:00');
});

test('a full page request sends the key, json format, user agent and issue-count order', function () {
    comicVineFake([], 0);

    app(ComicVineProvider::class)->page(null, '200');

    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://comicvine.gamespot.com/api/volumes/?')
        && $r['api_key'] === 'secret-key' && $r['format'] === 'json' && $r['sort'] === 'count_of_issues:desc'
        && $r['limit'] === 100 && $r['offset'] === 200 && ! isset($r['filter'])
        && str_contains($r['field_list'], 'count_of_issues')
        && $r->header('User-Agent')[0] === config('bookflix.provider_user_agent'));
});

test('an incremental request filters by update time in Pacific time and sorts newest first', function () {
    comicVineFake([], 0);

    app(ComicVineProvider::class)->page(now()->utc()->setDate(2026, 10, 1)->setTime(20, 0, 0)->toImmutable(), null);

    Http::assertSent(fn ($r) => $r['sort'] === 'date_last_updated:desc'
        && preg_match('/^date_last_updated:2026-10-01 13:00:00\|\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $r['filter']) === 1);
});

test('the token is the next offset and stops at the total or an empty page', function (int $offset, int $total, array $results, ?string $expected) {
    comicVineFake($results, $total);

    expect(app(ComicVineProvider::class)->page(null, (string) $offset)->nextPageToken)->toBe($expected);
})->with([
    'more to come' => [0, 250, [['id' => 1]], '100'],
    'last page' => [200, 250, [['id' => 1]], null],
    'empty page' => [100, 500, [], null],
]);

test('a volume that is a foreign edition has its credits and concepts fetched, the rest cost no extra request', function () {
    $french = comicVineVolume(['id' => 7, 'name' => 'Solo Leveling', 'deck' => null, 'description' => 'French publication.', 'publisher' => ['name' => 'Delcourt']]);
    $yen = comicVineVolume(['id' => 8, 'name' => 'Solo Leveling', 'deck' => null, 'description' => null, 'publisher' => ['name' => 'Yen Press']]);
    Http::fake([
        'comicvine.gamespot.com/api/volumes/*' => Http::response(['status_code' => 1, 'number_of_total_results' => 3, 'results' => [comicVineVolume(), $french, $yen]]),
        'comicvine.gamespot.com/api/volume/4050-7/*' => Http::response(['status_code' => 1, 'results' => ['people' => [['name' => 'Chugong'], ['name' => 'DUBU']], 'concepts' => []]]),
    ]);

    $page = app(ComicVineProvider::class)->page(null, null);
    $translation = app(ComicVineProvider::class)->normalize($page->records[1]);

    Http::assertSentCount(2);
    expect($translation->credits)->toBe(['Chugong', 'DUBU'])->and($translation->creators)->toBe([]);
});

test('concepts such as an imprint are weighed with the publisher', function () {
    $volume = comicVineVolume(['name' => 'Solo Leveling', 'description' => 'English edition.', 'publisher' => ['name' => 'Yen Press'], 'concepts' => [['name' => 'Ize Press']]]);

    expect(app(ComicVineProvider::class)->normalize(new ProviderRecord('comicvine', '1', $volume, null, now()->toImmutable()))->mediaTypeSlug)->toBe('manhwa');
});

test('find fetches the volume by its 4050 id and returns null when missing', function () {
    Http::fake(['comicvine.gamespot.com/api/volume/4050-2127/*' => Http::response(['status_code' => 1, 'results' => comicVineVolume()])]);

    expect(app(ComicVineProvider::class)->find('2127')->externalId)->toBe('2127');

    Http::fake(['comicvine.gamespot.com/*' => Http::response(['status_code' => 101, 'error' => 'Object Not Found', 'results' => []])]);

    expect(app(ComicVineProvider::class)->find('1'))->toBeNull();
});

test('missing credentials fail readably without a request', function () {
    config(['bookflix.providers.comicvine.api_key' => '']);
    Http::fake();

    expect(fn () => app(ComicVineProvider::class)->page(null, null))
        ->toThrow(MissingProviderCredentials::class, 'BOOKFLIX_COMICVINE_API_KEY');
    Http::assertNothingSent();
});

test('a sync run ends failed with the missing credentials reason', function () {
    config(['bookflix.providers.comicvine.api_key' => null]);
    $source = ExternalSource::factory()->create(['key' => 'comicvine', 'enabled' => true]);
    $run = IngestionRun::create(['external_source_id' => $source->id, 'mode' => IngestionMode::Full, 'started_at' => now()]);

    (new FetchProviderPage($run->id))->handle(app(IngestionPipeline::class));

    expect($run->fresh()->status)->toBe(IngestionStatus::Failed)
        ->and($run->fresh()->error)->toContain('BOOKFLIX_COMICVINE_API_KEY');
});

test('api status codes map to rate limiting and unavailability', function (int $status, string $exception) {
    comicVineFake([], 0, $status, 'Invalid API Key');

    expect(fn () => app(ComicVineProvider::class)->page(null, null))->toThrow($exception);
})->with([[107, RateLimitedByProvider::class], [100, ProviderUnavailable::class]]);

test('normalize tolerates shape drift and rejects off-host urls', function () {
    $media = app(ComicVineProvider::class)->normalize(new ProviderRecord('comicvine', '1', [
        'name' => ['x'], 'aliases' => 5, 'description' => ['y'], 'image' => 'nope', 'publisher' => 'Marvel',
        'start_year' => 'unknown', 'count_of_issues' => 'many', 'site_detail_url' => 'http://evil.test/x',
    ], 'http://evil.test/x', now()->toImmutable()));

    expect($media->title)->toBe('')->and($media->description)->toBeNull()->and($media->publishers)->toBe([])
        ->and($media->publicationYear)->toBeNull()->and($media->chapterCount)->toBeNull()
        ->and($media->coverUrl)->toBeNull()->and($media->sourceUrl)->toBeNull()->and($media->alternativeTitles)->toBe([]);

    $media = app(ComicVineProvider::class)->normalize(new ProviderRecord('comicvine', '1', comicVineVolume(['image' => ['medium_url' => 'https://evil.test/a.jpg']]), null, now()->toImmutable()));
    expect($media->coverUrl)->toBeNull();
});
