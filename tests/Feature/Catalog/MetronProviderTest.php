<?php

use App\Catalog\Data\ProviderRecord;
use App\Catalog\Ingestion\IngestionPipeline;
use App\Catalog\Ingestion\TaxonomyMapper;
use App\Catalog\Providers\Metron\MetronProvider;
use App\Catalog\Providers\MissingProviderCredentials;
use App\Enums\IngestionMode;
use App\Enums\IngestionStatus;
use App\Enums\PublicationStatus;
use App\Jobs\FetchProviderPage;
use App\Models\ExternalSource;
use App\Models\IngestionRun;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['bookflix.providers.metron.username' => 'user', 'bookflix.providers.metron.password' => 'pass']));

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function metronDetail(int $id = 7, array $overrides = []): array
{
    return [
        'id' => $id,
        'name' => 'Batman',
        'series_type' => ['id' => 1, 'name' => 'Ongoing Series'],
        'status' => 'Ongoing',
        'publisher' => ['id' => 2, 'name' => 'DC Comics'],
        'year_began' => 1940,
        'desc' => '<p>The <b>Dark Knight</b>.</p>',
        'issue_count' => 713,
        'genres' => [['id' => 1, 'name' => 'Super-Hero'], ['id' => 2, 'name' => 'Crime']],
        'cv_id' => 796,
        'gcd_id' => 2406,
        'modified' => '2026-10-02T09:00:00-04:00',
        ...$overrides,
    ];
}

/**
 * @param  list<int>  $ids
 */
function metronFake(array $ids, ?string $next = null): void
{
    Http::fake([
        'metron.cloud/api/series/?*' => Http::response(['count' => count($ids), 'next' => $next, 'results' => array_map(fn (int $id) => ['id' => $id, 'name' => "S{$id}"], $ids)]),
        'metron.cloud/api/series/*' => fn ($request) => Http::response(metronDetail((int) basename(rtrim($request->url(), '/')))),
    ]);
}

test('page merges list and detail into a comic book with publisher, genres, cross ids and urls', function () {
    metronFake([7]);

    $page = app(MetronProvider::class)->page(null, null);
    $media = app(MetronProvider::class)->normalize($page->records[0]);

    expect($media->title)->toBe('Batman')->and($media->mediaTypeSlug)->toBe('comic-book')
        ->and($media->description)->toBe('The Dark Knight.')
        ->and($media->publishers)->toBe(['DC Comics'])->and($media->publicationYear)->toBe(1940)
        ->and($media->chapterCount)->toBe(713)->and($media->status)->toBe(PublicationStatus::Ongoing)
        ->and($media->crossIds)->toBe(['comicvine' => '796', 'gcd' => '2406'])
        ->and($media->coverUrl)->toBeNull()
        ->and($media->sourceUrl)->toBe('https://metron.cloud/series/7/')
        ->and(app(TaxonomyMapper::class)->map('metron', $media))->toBe(['genres' => ['crime'], 'themes' => [], 'tags' => ['super-hero']])
        ->and($page->maxUpdatedAt->utc()->toIso8601String())->toBe('2026-10-02T13:00:00+00:00');
});

test('requests use basic auth, the user agent and modified_gt only when incremental', function () {
    metronFake([]);

    app(MetronProvider::class)->page(null, null);
    app(MetronProvider::class)->page(now()->utc()->setDate(2026, 10, 1)->setTime(8, 30, 5)->toImmutable(), '3:0');

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('user:pass'))
        && $r->header('User-Agent')[0] === config('bookflix.provider_user_agent'));
    Http::assertSent(fn ($r) => (string) $r['page'] === '1' && ! isset($r['modified_gt']));
    Http::assertSent(fn ($r) => (string) $r['page'] === '3' && $r['modified_gt'] === '2026-10-01T08:30:05');
});

test('a page makes one list call and at most 18 detail calls and resumes mid-page', function () {
    metronFake(range(1, 30), 'https://metron.cloud/api/series/?page=2');

    $first = app(MetronProvider::class)->page(null, null);

    expect($first->records)->toHaveCount(18)->and($first->nextPageToken)->toBe('1:18');
    Http::assertSentCount(19);

    $second = app(MetronProvider::class)->page(null, '1:18');

    expect($second->records)->toHaveCount(12)->and($second->records[0]->externalId)->toBe('19')->and($second->nextPageToken)->toBe('2:0');
});

test('the last list page and an empty page end the run', function () {
    metronFake([1, 2]);
    expect(app(MetronProvider::class)->page(null, '4:0')->nextPageToken)->toBeNull();

    metronFake([], 'https://metron.cloud/api/series/?page=9');
    expect(app(MetronProvider::class)->page(null, '9:0')->nextPageToken)->toBeNull();
});

test('find returns the series or null on a 404', function () {
    Http::fake(['metron.cloud/api/series/7/' => Http::response(metronDetail()), 'metron.cloud/api/series/8/' => Http::response(['detail' => 'Not found.'], 404)]);

    expect(app(MetronProvider::class)->find('7')->externalId)->toBe('7')
        ->and(app(MetronProvider::class)->find('8'))->toBeNull();
});

test('collected editions are graphic novels', function (string $type, string $slug) {
    $record = new ProviderRecord('metron', '1', metronDetail(1, ['series_type' => ['name' => $type]]), null, now()->toImmutable());

    expect(app(MetronProvider::class)->normalize($record)->mediaTypeSlug)->toBe($slug);
})->with([['Hardcover', 'graphic-novel'], ['Trade Paperback', 'graphic-novel'], ['Limited Series', 'comic-book']]);

test('missing credentials fail readably without a request and the run ends failed', function () {
    config(['bookflix.providers.metron.password' => null]);
    Http::fake();

    expect(fn () => app(MetronProvider::class)->page(null, null))->toThrow(MissingProviderCredentials::class, 'BOOKFLIX_METRON_PASSWORD');
    Http::assertNothingSent();

    $source = ExternalSource::factory()->create(['key' => 'metron', 'enabled' => true]);
    $run = IngestionRun::create(['external_source_id' => $source->id, 'mode' => IngestionMode::Full, 'started_at' => now()]);
    (new FetchProviderPage($run->id))->handle(app(IngestionPipeline::class));

    expect($run->fresh()->status)->toBe(IngestionStatus::Failed)->and($run->fresh()->error)->toContain('BOOKFLIX_METRON_USERNAME');
});

test('normalize tolerates shape drift', function () {
    $media = app(MetronProvider::class)->normalize(new ProviderRecord('metron', '1', [
        'name' => [], 'series_type' => 'x', 'publisher' => 'DC', 'year_began' => 'old', 'desc' => 5, 'issue_count' => '9',
        'genres' => 'Crime', 'cv_id' => 'abc', 'gcd_id' => null, 'status' => 3,
    ], 'http://evil.test/', now()->toImmutable()));

    expect($media->title)->toBe('')->and($media->publishers)->toBe([])->and($media->publicationYear)->toBeNull()
        ->and($media->description)->toBeNull()->and($media->chapterCount)->toBeNull()->and($media->genres)->toBe([])
        ->and($media->crossIds)->toBe([])->and($media->status)->toBe(PublicationStatus::Unknown)->and($media->sourceUrl)->toBeNull();
});
