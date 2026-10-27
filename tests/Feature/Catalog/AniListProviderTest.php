<?php

use App\Catalog\Data\ProviderRecord;
use App\Catalog\Providers\AniList\AniListProvider;
use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Enums\CreatorRole;
use Illuminate\Support\Facades\Http;

test('format and country map to a media type', function (string $fixture, string $slug) {
    $record = new ProviderRecord('anilist', '1', providerFixture("anilist/{$fixture}.json"), null, now()->toImmutable());
    expect(app(AniListProvider::class)->normalize($record)->mediaTypeSlug)->toBe($slug);
})->with([
    ['manga-berserk', 'manga'],          // MANGA + JP
    ['manhwa-solo-leveling', 'manhwa'],  // MANGA + KR
    ['manhua', 'manhua'],                // MANGA + CN
    ['light-novel', 'light-novel'],      // NOVEL
]);

test('adult records are flagged so ingestion can skip them', function () {
    $record = new ProviderRecord('anilist', '9', providerFixture('anilist/adult.json'), null, now()->toImmutable());
    expect(app(AniListProvider::class)->normalize($record)->isAdult)->toBeTrue();
});

test('staff roles map to creator roles and the MAL id becomes a cross id', function () {
    $media = app(AniListProvider::class)->normalize(new ProviderRecord('anilist', '30002', providerFixture('anilist/manga-berserk.json'), null, now()->toImmutable()));
    expect(collect($media->creators)->pluck('role')->all())->toContain(CreatorRole::Author)
        ->and($media->crossIds['myanimelist'])->toBe('2')
        ->and($media->alternativeTitles)->toContain('ベルセルク');
});

test('popularity, favourites and the 0-100 score become metrics on a 0-5 scale', function (array $extra, array $expected) {
    $payload = [...providerFixture('anilist/manga-berserk.json'), 'popularity' => null, 'favourites' => null, 'averageScore' => null, 'meanScore' => null, ...$extra];

    $media = app(AniListProvider::class)->normalize(new ProviderRecord('anilist', '30002', $payload, null, now()->toImmutable()));

    expect($media->metrics)->toBe($expected);
})->with([
    'all signals' => [['popularity' => 12000, 'favourites' => 340, 'averageScore' => 92, 'meanScore' => 90], ['popularity' => 12000, 'favourites' => 340, 'average_rating' => 4.6]],
    'mean score fallback' => [['popularity' => 5, 'meanScore' => 80], ['popularity' => 5, 'average_rating' => 4.0]],
    'nothing known' => [['popularity' => 0, 'averageScore' => 0], []],
]);

test('every request asks for the popularity fields', function () {
    Http::fake(['graphql.anilist.co' => Http::response(['data' => ['Media' => null]])]);

    app(AniListProvider::class)->find('1');

    Http::assertSent(fn ($r) => str_contains($r['query'], 'popularity favourites averageScore meanScore'));
});

test('page reads one GraphQL page and hands back the next page token and the newest update', function () {
    $media = providerFixture('anilist/page-1.json')['data']['Page']['media'];
    Http::fake(['graphql.anilist.co' => Http::sequence()->push(providerFixture('anilist/page-1.json'))->push(['data' => ['Page' => ['pageInfo' => ['hasNextPage' => false], 'media' => []]]])]);

    $first = app(AniListProvider::class)->page(null, null);
    $last = app(AniListProvider::class)->page(null, $first->nextPageToken);

    expect($first->records)->toHaveCount(3)->and($first->records[0])->toBeInstanceOf(ProviderRecord::class)
        ->and($first->nextPageToken)->toBe('2')
        ->and($first->maxUpdatedAt->getTimestamp())->toBe(max(array_column($media, 'updatedAt')))
        ->and($last->records)->toBe([])->and($last->nextPageToken)->toBeNull()->and($last->maxUpdatedAt)->toBeNull();
    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => $r['variables']['page'] === 1 && $r['variables']['sort'] === ['POPULARITY_DESC']);
    Http::assertSent(fn ($r) => $r['variables']['page'] === 2 && $r->hasHeader('User-Agent', config('bookflix.provider_user_agent')));
});

test('a 429 surfaces as a RateLimitedByProvider exception carrying retry-after', function () {
    Http::fake(['graphql.anilist.co' => Http::response(null, 429, ['Retry-After' => '30'])]);
    expect(fn () => app(AniListProvider::class)->find('1'))->toThrow(RateLimitedByProvider::class);
});

test('a 5xx surfaces as ProviderUnavailable and a 404 as null', function () {
    Http::fake(['graphql.anilist.co' => Http::sequence()->push(null, 502)->push(['errors' => [['message' => 'Not Found.', 'status' => 404]], 'data' => ['Media' => null]], 404)]);

    expect(fn () => app(AniListProvider::class)->find('1'))->toThrow(ProviderUnavailable::class)
        ->and(app(AniListProvider::class)->find('1'))->toBeNull();
});

test('an incremental page stops at the first record older than the cursor and has no next page', function () {
    $page = providerFixture('anilist/page-1.json');
    $newest = max(array_column($page['data']['Page']['media'], 'updatedAt'));
    Http::fake(['graphql.anilist.co' => Http::response($page)]);

    $result = app(AniListProvider::class)->page(now()->toImmutable()->setTimestamp($newest + 1), null);

    expect($result->records)->toBe([])->and($result->nextPageToken)->toBeNull();
    Http::assertSent(fn ($r) => $r['variables']['sort'] === ['UPDATED_AT_DESC']);
});

test('cover and source urls are kept only when https on an allowed host', function (?string $url, ?string $expected) {
    $payload = [...providerFixture('anilist/manga-berserk.json'), 'coverImage' => ['extraLarge' => $url, 'large' => null], 'siteUrl' => $url];

    $media = app(AniListProvider::class)->normalize(new ProviderRecord('anilist', '30002', $payload, $url, now()->toImmutable()));

    expect($media->coverUrl)->toBe($expected)->and($media->sourceUrl)->toBe($expected);
})->with([
    'allowed cdn' => ['https://s4.anilist.co/file/anilistcdn/media/manga/cover/large/bx30002.jpg', 'https://s4.anilist.co/file/anilistcdn/media/manga/cover/large/bx30002.jpg'],
    'allowed site' => ['https://anilist.co/manga/30002', 'https://anilist.co/manga/30002'],
    'plain http' => ['http://anilist.co/manga/30002', null],
    'javascript' => ['javascript:alert(1)', null],
    'data uri' => ['data:text/html;base64,PHNjcmlwdD4=', null],
    'other host' => ['https://evil.example/x.jpg', null],
    'lookalike host' => ['https://anilist.co.evil.example/x.jpg', null],
    'userinfo trick' => ['https://anilist.co@evil.example/x.jpg', null],
    'not a url' => ['  ', null],
]);

test('descriptions are decoded then stripped, so no markup survives', function () {
    $payload = [...providerFixture('anilist/manga-berserk.json'), 'description' => 'Tom &amp; Jerry<br>next &lt;script&gt;alert(1)&lt;/script&gt;<b>bold</b> &lt;img src=x onerror=y&gt;'];

    $media = app(AniListProvider::class)->normalize(new ProviderRecord('anilist', '30002', $payload, null, now()->toImmutable()));

    expect($media->description)->toBe("Tom & Jerry\nnext alert(1)bold");
});
