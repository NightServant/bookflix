<?php

use App\Catalog\Data\ProviderRecord;
use App\Catalog\Ingestion\TaxonomyMapper;
use App\Catalog\Providers\MangaUpdates\MangaUpdatesProvider;
use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Enums\AgeRating;
use App\Enums\CreatorRole;
use App\Enums\PublicationStatus;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mangaUpdatesSeries(array $overrides = []): array
{
    return [
        'series_id' => 12573233956,
        'title' => 'On the Way to Meet Mom',
        'url' => 'https://www.mangaupdates.com/series/5rxs1es/on-the-way-to-meet-mom',
        'description' => 'A boy <b>searches</b> for his mother.',
        'image' => ['url' => ['original' => 'https://cdn.mangaupdates.com/image/i515488.jpg']],
        'type' => 'Manhwa',
        'year' => '2024',
        'bayesian_rating' => 9.15,
        'rating_votes' => 332,
        'genres' => [['genre' => 'Adventure'], ['genre' => 'Sci-fi'], ['genre' => 'School Life']],
        'last_updated' => ['timestamp' => 1792453375],
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $series
 */
function mangaUpdatesFake(array $series, int $total): void
{
    Http::fake(['api.mangaupdates.com/v1/series/search' => Http::response([
        'total_hits' => $total,
        'results' => array_map(fn (array $record) => ['record' => $record], $series),
    ])]);
}

/**
 * @param  array<string, mixed>  $series
 */
function mangaUpdatesRecord(array $series): ProviderRecord
{
    return new ProviderRecord('mangaupdates', '1', $series, $series['url'] ?? null, now()->toImmutable());
}

test('page maps title, type, taxonomy, metrics and urls', function () {
    mangaUpdatesFake([mangaUpdatesSeries()], 1);

    $page = app(MangaUpdatesProvider::class)->page(null, null);
    $media = app(MangaUpdatesProvider::class)->normalize($page->records[0]);
    $mapped = app(TaxonomyMapper::class)->map('mangaupdates', $media);

    expect($page->records[0]->externalId)->toBe('12573233956')
        ->and($media->title)->toBe('On the Way to Meet Mom')->and($media->mediaTypeSlug)->toBe('manhwa')
        ->and($media->language)->toBe('ko')->and($media->countryOfOrigin)->toBe('KR')->and($media->publicationYear)->toBe(2024)
        ->and($media->description)->toBe('A boy searches for his mother.')
        ->and($media->metrics)->toBe(['ratings_count' => 332, 'average_rating' => 4.58])
        ->and($media->crossIds)->toBe([])
        ->and($mapped)->toBe(['genres' => ['adventure', 'science-fiction'], 'themes' => ['school'], 'tags' => []])
        ->and($media->coverUrl)->toBe('https://cdn.mangaupdates.com/image/i515488.jpg')
        ->and($media->sourceUrl)->toBe('https://www.mangaupdates.com/series/5rxs1es/on-the-way-to-meet-mom')
        ->and($page->maxUpdatedAt->getTimestamp())->toBe(1792453375);
});

test('a full sync ranks by readers and asks the API to leave adult genres out', function () {
    mangaUpdatesFake([mangaUpdatesSeries()], 1);

    app(MangaUpdatesProvider::class)->page(null, '3');

    Http::assertSent(fn ($r) => $r['page'] === 3 && $r['perpage'] === 50 && $r['orderby'] === 'list_reading' && $r['exclude_genre'] === ['Hentai', 'Adult']);
});

test('the token is the next page and ends with the hits', function (int $page, int $total, ?string $expected) {
    mangaUpdatesFake([mangaUpdatesSeries()], $total);

    expect(app(MangaUpdatesProvider::class)->page(null, (string) $page)->nextPageToken)->toBe($expected);
})->with([
    'first of many' => [1, 10000, '2'],
    'exactly the last page' => [2, 100, null],
    'API window end' => [200, 10000, null],
]);

test('an empty page ends the run', function () {
    mangaUpdatesFake([], 10000);
    expect(app(MangaUpdatesProvider::class)->page(null, '4')->nextPageToken)->toBeNull();
});

test('an incremental page stops at the first series last updated before the cursor', function () {
    mangaUpdatesFake([
        mangaUpdatesSeries(['series_id' => 1, 'last_updated' => ['timestamp' => 2000]]),
        mangaUpdatesSeries(['series_id' => 2, 'last_updated' => ['timestamp' => 900]]),
        mangaUpdatesSeries(['series_id' => 3, 'last_updated' => ['timestamp' => 3000]]),
    ], 500);

    $page = app(MangaUpdatesProvider::class)->page(now()->setTimestamp(1000)->toImmutable(), null);

    expect(array_map(fn ($r) => $r->externalId, $page->records))->toBe(['1'])->and($page->nextPageToken)->toBeNull();
    Http::assertSent(fn ($r) => $r['orderby'] === 'date_added');
});

test('adult series are flagged so they are dropped, and smut is only mature', function () {
    $adult = app(MangaUpdatesProvider::class)->normalize(mangaUpdatesRecord(mangaUpdatesSeries(['genres' => [['genre' => 'Hentai']]])));
    $smut = app(MangaUpdatesProvider::class)->normalize(mangaUpdatesRecord(mangaUpdatesSeries(['genres' => [['genre' => 'Smut']]])));

    expect($adult->isAdult)->toBeTrue()->and($adult->ageRating)->toBe(AgeRating::Adult)
        ->and($smut->isAdult)->toBeFalse()->and($smut->ageRating)->toBe(AgeRating::Mature);
});

test('type maps to a media type and other types are skipped', function (string $type, string $slug) {
    expect(app(MangaUpdatesProvider::class)->normalize(mangaUpdatesRecord(mangaUpdatesSeries(['type' => $type])))->mediaTypeSlug)->toBe($slug);
})->with([['Manga', 'manga'], ['Manhwa', 'manhwa'], ['Manhua', 'manhua'], ['Novel', 'light-novel'], ['Doujinshi', ''], ['Artbook', '']]);

test('find reads the full series with authors, categories, status and readers', function () {
    Http::fake(['api.mangaupdates.com/v1/series/12573233956' => Http::response(mangaUpdatesSeries([
        'associated' => [['title' => 'Mom'], ['title' => 'On the Way to Meet Mom']],
        'authors' => [['name' => 'gomyang', 'type' => 'Author'], ['name' => 'gomyang', 'type' => 'Artist']],
        'categories' => [['category' => 'Adopted Child/ren', 'votes' => 1], ['category' => 'Unpopular', 'votes' => 0]],
        'completed' => true,
        'rank' => ['lists' => ['reading' => 597]],
    ]))]);

    $media = app(MangaUpdatesProvider::class)->normalize(app(MangaUpdatesProvider::class)->find('12573233956'));

    expect($media->alternativeTitles)->toBe(['Mom'])->and($media->tags)->toBe(['Adopted Child/ren'])
        ->and(collect($media->creators)->pluck('role')->all())->toBe([CreatorRole::Author, CreatorRole::Artist])
        ->and($media->status)->toBe(PublicationStatus::Completed)->and($media->metrics['follows'])->toBe(597);
});

test('find returns null on 404', function () {
    Http::fake(['api.mangaupdates.com/*' => Http::response(['reason' => 'Not found'], 404)]);
    expect(app(MangaUpdatesProvider::class)->find('1'))->toBeNull();
});

test('a 429 is a rate limit and a 5xx an outage', function () {
    Http::fake(['api.mangaupdates.com/*' => Http::sequence()->push(null, 429, ['Retry-After' => '30'])->push(null, 502)]);

    expect(fn () => app(MangaUpdatesProvider::class)->page(null, null))->toThrow(RateLimitedByProvider::class)
        ->and(fn () => app(MangaUpdatesProvider::class)->page(null, null))->toThrow(ProviderUnavailable::class);
});

test('cover and source urls are kept only on allowed hosts', function () {
    $media = app(MangaUpdatesProvider::class)->normalize(new ProviderRecord('mangaupdates', '1', mangaUpdatesSeries(['image' => ['url' => ['original' => 'http://cdn.mangaupdates.com/a.jpg']]]), 'https://evil.example/s', now()->toImmutable()));

    expect($media->coverUrl)->toBeNull()->and($media->sourceUrl)->toBeNull();
});

test('shape drift yields empty values rather than exceptions', function (array $payload) {
    $media = app(MangaUpdatesProvider::class)->normalize(new ProviderRecord('mangaupdates', 'x', $payload, null, now()->toImmutable()));

    expect($media->title)->toBe('')->and($media->mediaTypeSlug)->toBe('')->and($media->metrics)->toBe([])
        ->and($media->genres)->toBe([])->and($media->coverUrl)->toBeNull()->and($media->isAdult)->toBeFalse();
})->with([
    'empty' => [[]],
    'wrong types' => [['title' => 5, 'type' => [], 'genres' => 'x', 'authors' => 3, 'categories' => 'y', 'year' => [], 'image' => 'z', 'rating_votes' => 'many', 'bayesian_rating' => 'high', 'rank' => 4]],
    'nested nulls' => [['genres' => [null, ['genre' => null]], 'authors' => [null, ['name' => 'a']], 'categories' => [null], 'associated' => [null], 'image' => ['url' => null]]],
]);

test('a search result without a record is skipped', function () {
    Http::fake(['api.mangaupdates.com/*' => Http::response(['total_hits' => 3, 'results' => [['record' => null], 'junk', ['record' => mangaUpdatesSeries()]]])]);

    expect(app(MangaUpdatesProvider::class)->page(null, null)->records)->toHaveCount(1);
});
