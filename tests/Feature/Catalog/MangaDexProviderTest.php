<?php

use App\Catalog\Data\ProviderRecord;
use App\Catalog\Ingestion\MappedTaxonomy;
use App\Catalog\Ingestion\TaxonomyMapper;
use App\Catalog\Providers\MangaDex\MangaDexProvider;
use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Enums\AgeRating;
use App\Enums\CreatorRole;
use App\Enums\PublicationStatus;
use Illuminate\Support\Facades\Http;

const MD_ID = '32d76d19-8a05-4db0-9fc2-e0b0648fe9d0';

/**
 * @param  array<string, mixed>  $attributes
 * @param  list<array<string, mixed>>  $relationships
 * @return array<string, mixed>
 */
function mangaDexItem(array $attributes = [], string $id = MD_ID, array $relationships = []): array
{
    return [
        'id' => $id,
        'type' => 'manga',
        'attributes' => [
            'title' => ['ko-ro' => 'Na Honjaman Level-Up'],
            'altTitles' => [['ko' => '나 혼자만 레벨업'], ['en' => 'Solo Leveling'], ['ja-ro' => 'Ore Dake Level Up na Ken']],
            'description' => ['en' => 'Hunter <b>Jin-Woo</b> levels up.', 'de' => 'Deutsch'],
            'originalLanguage' => 'ko',
            'lastChapter' => '200',
            'year' => 2018,
            'status' => 'completed',
            'contentRating' => 'safe',
            'links' => ['al' => '105398', 'mu' => '5rxs1es', 'mal' => '103299'],
            'tags' => [
                ['attributes' => ['name' => ['en' => 'Action'], 'group' => 'genre']],
                ['attributes' => ['name' => ['en' => 'Isekai'], 'group' => 'theme']],
                ['attributes' => ['name' => ['en' => 'Long Strip'], 'group' => 'format']],
            ],
            'updatedAt' => '2026-10-01T10:00:00+00:00',
            ...$attributes,
        ],
        'relationships' => $relationships ?: [
            ['type' => 'author', 'attributes' => ['name' => 'Chugong']],
            ['type' => 'artist', 'attributes' => ['name' => 'Dubu']],
            ['type' => 'cover_art', 'attributes' => ['fileName' => 'cover.jpg']],
        ],
    ];
}

/**
 * @param  array<string, mixed>  $item
 */
function mangaDexRecord(array $item): ProviderRecord
{
    return new ProviderRecord('mangadex', $item['id'] ?? 'x', $item, 'https://mangadex.org/title/'.($item['id'] ?? 'x'), now()->toImmutable());
}

/**
 * @param  list<array<string, mixed>>  $items
 * @param  array<string, mixed>  $statistics
 */
function mangaDexFake(array $items, int $total, array $statistics = []): void
{
    Http::fake([
        'api.mangadex.org/statistics/*' => Http::response(['result' => 'ok', 'statistics' => $statistics]),
        'api.mangadex.org/manga*' => Http::response(['result' => 'ok', 'data' => $items, 'total' => $total]),
    ]);
}

test('page maps titles, type, taxonomy, creators, links and urls', function () {
    mangaDexFake([mangaDexItem()], 1, [MD_ID => ['follows' => 320768, 'rating' => ['average' => 9.3, 'bayesian' => 9.32]]]);

    $page = app(MangaDexProvider::class)->page(null, null);
    $media = app(MangaDexProvider::class)->normalize($page->records[0]);
    $mapped = app(TaxonomyMapper::class)->map('mangadex', $media);

    expect($media->title)->toBe('Solo Leveling')
        ->and($media->alternativeTitles)->toBe(['Ore Dake Level Up na Ken', 'Na Honjaman Level-Up', '나 혼자만 레벨업'])
        ->and($media->mediaTypeSlug)->toBe('manhwa')
        ->and($media->description)->toBe('Hunter Jin-Woo levels up.')
        ->and($media->language)->toBe('ko')->and($media->publicationYear)->toBe(2018)->and($media->chapterCount)->toBe(200)
        ->and($media->status)->toBe(PublicationStatus::Completed)->and($media->ageRating)->toBe(AgeRating::All)
        ->and(collect($media->creators)->pluck('role')->all())->toBe([CreatorRole::Author, CreatorRole::Artist])
        ->and($media->crossIds)->toBe(['anilist' => '105398', 'myanimelist' => '103299', 'mangaupdates' => '12573233956'])
        ->and($media->coverUrl)->toBe('https://uploads.mangadex.org/covers/'.MD_ID.'/cover.jpg.512.jpg')
        ->and($media->sourceUrl)->toBe('https://mangadex.org/title/'.MD_ID)
        ->and($media->metrics)->toBe(['follows' => 320768, 'average_rating' => 4.66])
        ->and($mapped)->toEqual(new MappedTaxonomy(genres: ['action-and-adventure', 'isekai'], tags: ['long-strip' => 'Long Strip']))
        ->and($page->maxUpdatedAt->toIso8601String())->toBe('2026-10-01T10:00:00+00:00');
});

test('a page asks for safe and suggestive titles most followed first and makes one statistics call', function () {
    mangaDexFake([mangaDexItem(), mangaDexItem(id: 'b')], 2);

    app(MangaDexProvider::class)->page(null, null);

    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/manga?') && str_contains(urldecode($r->url()), 'contentRating[]=safe&contentRating[]=suggestive')
        && str_contains(urldecode($r->url()), 'order[followedCount]=desc') && str_contains(urldecode($r->url()), 'includes[]=author&includes[]=artist&includes[]=cover_art')
        && str_contains($r->url(), 'limit=100') && ! str_contains($r->url(), 'updatedAtSince'));
    Http::assertSent(fn ($r) => str_contains($r->url(), '/statistics/manga') && str_contains(urldecode($r->url()), 'manga[]='.MD_ID.'&manga[]=b'));
});

test('an incremental page orders by update time and filters from the cursor', function () {
    mangaDexFake([], 0);

    app(MangaDexProvider::class)->page(now()->utc()->setDate(2026, 10, 1)->setTime(8, 30, 5)->toImmutable(), null);

    Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'order[updatedAt]=desc') && str_contains(urldecode($r->url()), 'updatedAtSince=2026-10-01T08:30:05'));
    Http::assertSentCount(1);
});

test('the token is the next offset and stops at the total and at the 10000 window', function (int $offset, int $total, ?string $expected) {
    mangaDexFake([mangaDexItem()], $total);

    $page = app(MangaDexProvider::class)->page(null, (string) $offset);

    expect($page->nextPageToken)->toBe($expected);
    Http::assertSent(fn ($r) => ! str_contains($r->url(), '/manga?') || str_contains($r->url(), "offset={$offset}"));
})->with([
    'first page' => [0, 450, '100'],
    'last page of the catalogue' => [400, 450, null],
    'at the window edge' => [9800, 60000, '9900'],
    'window exhausted' => [9900, 60000, null],
]);

test('an empty page ends the run', function () {
    mangaDexFake([], 500);
    expect(app(MangaDexProvider::class)->page(null, '100')->nextPageToken)->toBeNull();
});

test('explicit content is flagged adult so it is dropped', function (string $rating, bool $adult) {
    $media = app(MangaDexProvider::class)->normalize(mangaDexRecord(mangaDexItem(['contentRating' => $rating])));

    expect($media->isAdult)->toBe($adult);
})->with([['safe', false], ['suggestive', false], ['erotica', true], ['pornographic', true]]);

test('type follows the original language and unknown languages are skipped', function (string $language, string $slug) {
    expect(app(MangaDexProvider::class)->normalize(mangaDexRecord(mangaDexItem(['originalLanguage' => $language, 'tags' => []])))->mediaTypeSlug)->toBe($slug);
})->with([['ja', 'manga'], ['ko', 'manhwa'], ['zh', 'manhua'], ['zh-hk', 'manhua'], ['fr', '']]);

test('a long strip follows the country: manhwa, manhua, and a webcomic for Japanese and other work', function (string $language, string $slug) {
    expect(app(MangaDexProvider::class)->normalize(mangaDexRecord(mangaDexItem(['originalLanguage' => $language])))->mediaTypeSlug)->toBe($slug);
})->with([['ko', 'manhwa'], ['zh', 'manhua'], ['zh-hk', 'manhua'], ['ja', 'webcomic'], ['en', 'webcomic'], ['fr', 'webcomic']]);

test('a light novel tag makes a light novel of a Japanese title', function () {
    $tags = [['attributes' => ['name' => ['en' => 'Light Novel'], 'group' => 'format']]];

    expect(app(MangaDexProvider::class)->normalize(mangaDexRecord(mangaDexItem(['originalLanguage' => 'ja', 'tags' => $tags])))->mediaTypeSlug)->toBe('light-novel');
});

test('title falls back to romanised Japanese then any title', function () {
    $item = mangaDexItem(['title' => ['ja' => '漫画'], 'altTitles' => [['ja-ro' => 'Manga']]]);
    expect(app(MangaDexProvider::class)->normalize(mangaDexRecord($item))->title)->toBe('Manga');

    $item = mangaDexItem(['title' => ['ja' => '漫画'], 'altTitles' => []]);
    expect(app(MangaDexProvider::class)->normalize(mangaDexRecord($item))->title)->toBe('漫画');
});

test('cover and source urls are kept only on allowed hosts', function () {
    $item = mangaDexItem(relationships: [['type' => 'cover_art', 'attributes' => ['fileName' => 'a.png']]]);
    $record = new ProviderRecord('mangadex', MD_ID, $item, 'https://evil.example/title/1', now()->toImmutable());

    $media = app(MangaDexProvider::class)->normalize($record);

    expect($media->coverUrl)->toStartWith('https://uploads.mangadex.org/covers/')->and($media->sourceUrl)->toBeNull();
});

test('find returns one title with its statistics, or null on 404', function () {
    Http::fake([
        'api.mangadex.org/statistics/*' => Http::response(['statistics' => [MD_ID => ['follows' => 7]]]),
        'api.mangadex.org/manga/'.MD_ID.'*' => Http::response(['result' => 'ok', 'data' => mangaDexItem()]),
        'api.mangadex.org/manga/missing*' => Http::response(['result' => 'error'], 404),
    ]);

    $record = app(MangaDexProvider::class)->find(MD_ID);

    expect($record->externalId)->toBe(MD_ID)->and(app(MangaDexProvider::class)->normalize($record)->metrics)->toBe(['follows' => 7])
        ->and(app(MangaDexProvider::class)->find('missing'))->toBeNull();
});

test('a 429 is a rate limit and a 5xx an outage', function () {
    Http::fake(['api.mangadex.org/*' => Http::sequence()->push(null, 429, ['Retry-After' => '30'])->push(null, 503)]);

    expect(fn () => app(MangaDexProvider::class)->page(null, null))->toThrow(RateLimitedByProvider::class)
        ->and(fn () => app(MangaDexProvider::class)->page(null, null))->toThrow(ProviderUnavailable::class);
});

test('shape drift yields empty values rather than exceptions', function (array $payload) {
    $media = app(MangaDexProvider::class)->normalize(new ProviderRecord('mangadex', 'x', $payload, null, now()->toImmutable()));

    expect($media->title)->toBe('')->and($media->mediaTypeSlug)->toBe('')->and($media->metrics)->toBe([])
        ->and($media->crossIds)->toBe([])->and($media->coverUrl)->toBeNull()->and($media->isAdult)->toBeFalse();
})->with([
    'empty' => [[]],
    'wrong types' => [['id' => 5, 'attributes' => ['title' => 'x', 'altTitles' => 'y', 'tags' => 3, 'links' => 'z', 'year' => '2020', 'originalLanguage' => []], 'relationships' => 'no', 'statistics' => 'no']],
    'nested nulls' => [['attributes' => ['title' => null, 'links' => ['al' => null, 'mu' => []], 'tags' => [['attributes' => null]]], 'relationships' => [null, ['type' => 'author']], 'statistics' => ['follows' => 'many', 'rating' => 'high']]],
]);
