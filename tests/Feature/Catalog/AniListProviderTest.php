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

test('changedSince pages through the GraphQL feed until hasNextPage is false', function () {
    Http::fake(['graphql.anilist.co' => Http::sequence()->push(providerFixture('anilist/page-1.json'))->push(['data' => ['Page' => ['pageInfo' => ['hasNextPage' => false], 'media' => []]]])]);
    expect(app(AniListProvider::class)->changedSince(null)->count())->toBeGreaterThan(0);
    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => $r->hasHeader('User-Agent', config('bookflix.provider_user_agent')));
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

test('an incremental sync stops at the first record older than the cursor', function () {
    $page = providerFixture('anilist/page-1.json');
    $newest = $page['data']['Page']['media'][0]['updatedAt'];
    Http::fake(['graphql.anilist.co' => Http::response($page)]);

    $records = app(AniListProvider::class)->changedSince(now()->toImmutable()->setTimestamp($newest + 1));

    expect($records->count())->toBe(0);
});
