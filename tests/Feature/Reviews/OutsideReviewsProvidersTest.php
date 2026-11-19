<?php

use App\Catalog\Providers\AniList\AniListProvider;
use App\Catalog\Providers\Hardcover\HardcoverProvider;
use App\Catalog\Providers\MangaUpdates\MangaUpdatesProvider;
use App\Catalog\Providers\OpenLibrary\OpenLibraryProvider;
use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Reviews\Excerpt;
use Illuminate\Support\Facades\Http;

function aniNode(array $over = []): array
{
    return [...['id' => 11, 'summary' => 'A fine <b>read</b>.', 'score' => 85, 'private' => false, 'siteUrl' => 'https://anilist.co/review/11', 'createdAt' => 1_700_000_000, 'user' => ['name' => 'Reader']], ...$over];
}

function aniBody(array $nodes, mixed $average = 88): array
{
    return ['data' => ['Media' => ['siteUrl' => 'https://anilist.co/manga/30002', 'averageScore' => $average, 'reviews' => ['nodes' => $nodes]]]];
}

test('excerpts lose markup and extra whitespace', function () {
    expect(Excerpt::make("  <p>Great&nbsp;<i>story</i></p>\n\n  &lt;b&gt;really&lt;/b&gt;  "))->toBe('Great story really')
        ->and(Excerpt::make('<br>'))->toBeNull()
        ->and(Excerpt::make(null))->toBeNull();
});

test('excerpts are cut at a word with an ellipsis and never pass 300 characters', function () {
    $text = str_repeat('wonderful ', 60);
    $excerpt = Excerpt::make($text);

    expect(mb_strlen($excerpt))->toBeLessThanOrEqual(300)
        ->and($excerpt)->toEndWith('wonderful…')
        ->and(Excerpt::make(str_repeat('a', 400)))->toHaveLength(300)
        ->and(Excerpt::make(str_repeat('é', 300)))->toBe(str_repeat('é', 300))
        ->and(mb_strlen(Excerpt::make(str_repeat('日本語 ', 200))))->toBeLessThanOrEqual(300);
});

test('anilist maps the reviewers summary, score, name and link and the average score', function () {
    Http::fake(['graphql.anilist.co' => Http::response(aniBody([aniNode(), aniNode(['id' => 12, 'score' => 0, 'user' => null, 'summary' => 'Second.', 'siteUrl' => 'https://anilist.co/review/12'])]))]);

    $data = app(AniListProvider::class)->outsideReviews('30002');

    expect($data->reviews)->toHaveCount(2)
        ->and($data->reviews[0]->externalId)->toBe('11')
        ->and($data->reviews[0]->excerpt)->toBe('A fine read.')
        ->and($data->reviews[0]->rating)->toBe(85)
        ->and($data->reviews[0]->authorName)->toBe('Reader')
        ->and($data->reviews[0]->url)->toBe('https://anilist.co/review/11')
        ->and($data->reviews[0]->publishedAt->getTimestamp())->toBe(1_700_000_000)
        ->and($data->reviews[1]->rating)->toBe(0)
        ->and($data->reviews[1]->authorName)->toBeNull()
        ->and($data->rating->score)->toBe(88)
        ->and($data->rating->url)->toBe('https://anilist.co/manga/30002');
});

test('anilist skips private reviews, empty summaries and links off its hosts', function () {
    Http::fake(['graphql.anilist.co' => Http::response(aniBody([
        aniNode(['id' => 1, 'private' => true]),
        aniNode(['id' => 2, 'summary' => '  <br> ']),
        aniNode(['id' => 3, 'siteUrl' => 'https://evil.example/review/3']),
        aniNode(['id' => 4, 'siteUrl' => 'javascript:alert(1)']),
        aniNode(['id' => 5, 'siteUrl' => 'http://anilist.co/review/5']),
        aniNode(['id' => 6]),
    ]))]);

    $data = app(AniListProvider::class)->outsideReviews('1');

    expect(collect($data->reviews)->pluck('externalId')->all())->toBe(['6']);
});

test('anilist keeps at most ten reviews and no rating when it has no score', function () {
    Http::fake(['graphql.anilist.co' => Http::response(aniBody(array_map(fn ($i) => aniNode(['id' => $i, 'siteUrl' => "https://anilist.co/review/{$i}"]), range(1, 14)), null))]);

    $data = app(AniListProvider::class)->outsideReviews('1');

    expect($data->reviews)->toHaveCount(10)->and($data->rating)->toBeNull();
});

test('anilist failures are typed so the stored rows stay', function (callable $fake, string $exception) {
    Http::fake(['graphql.anilist.co' => $fake()]);

    expect(fn () => app(AniListProvider::class)->outsideReviews('1'))->toThrow($exception);
})->with([
    'server error' => [fn () => Http::response('', 503), ProviderUnavailable::class],
    'throttled' => [fn () => Http::response('', 429, ['Retry-After' => '30']), RateLimitedByProvider::class],
    'malformed' => [fn () => Http::response(['data' => ['Media' => null]]), ProviderUnavailable::class],
    'html' => [fn () => Http::response('<html>', 200), ProviderUnavailable::class],
]);

function hcBody(array $entries, array $book = []): array
{
    return ['data' => ['books' => [[...['slug' => 'dune', 'rating' => 4.25, 'ratings_count' => 321, 'user_books' => $entries], ...$book]]]];
}

function hcEntry(array $over = []): array
{
    return [...['id' => 5, 'rating' => 4.5, 'review_raw' => 'Loved it.', 'review_has_spoilers' => false, 'reviewed_at' => '2026-01-02T03:04:05Z', 'user' => ['username' => 'bookworm']], ...$over];
}

test('hardcover maps excerpt, 0-5 rating to 0-100, link, average and count, and skips spoilers', function () {
    config(['bookflix.providers.hardcover.api_token' => 'test-token']);
    Http::fake(['api.hardcover.app/*' => Http::response(hcBody([hcEntry(), hcEntry(['id' => 6, 'review_has_spoilers' => true]), hcEntry(['id' => 7, 'review_raw' => '  ']), hcEntry(['id' => 8, 'rating' => null, 'review_raw' => '<p>Text</p>'])]))]);

    $data = app(HardcoverProvider::class)->outsideReviews('99');

    expect(collect($data->reviews)->pluck('externalId')->all())->toBe(['5', '8'])
        ->and($data->reviews[0]->rating)->toBe(90)
        ->and($data->reviews[0]->authorName)->toBe('bookworm')
        ->and($data->reviews[0]->url)->toBe('https://hardcover.app/books/dune')
        ->and($data->reviews[0]->publishedAt->year)->toBe(2026)
        ->and($data->reviews[1]->rating)->toBeNull()
        ->and($data->reviews[1]->excerpt)->toBe('Text')
        ->and($data->rating->score)->toBe(85)
        ->and($data->rating->votes)->toBe(321);

    Http::assertSent(fn ($request) => $request->hasHeader('authorization', 'Bearer test-token') && str_contains($request['query'], 'review_has_spoilers: {_eq: false}'));
});

test('hardcover without a book, with GraphQL errors or without a token is a failure', function () {
    config(['bookflix.providers.hardcover.api_token' => 'test-token']);
    Http::fake(['api.hardcover.app/*' => Http::sequence()->push(['data' => ['books' => []]])->push(['errors' => [['message' => 'bad field']]])]);

    expect(fn () => app(HardcoverProvider::class)->outsideReviews('1'))->toThrow(ProviderUnavailable::class)
        ->and(fn () => app(HardcoverProvider::class)->outsideReviews('1'))->toThrow(ProviderUnavailable::class);

    config(['bookflix.providers.hardcover.api_token' => '']);

    expect(fn () => app(HardcoverProvider::class)->outsideReviews('1'))->toThrow(ProviderUnavailable::class);
});

test('open library gives the 1-5 average as 0-100 with the count and no review text', function () {
    Http::fake(['openlibrary.org/works/OL1W/ratings.json' => Http::response(['summary' => ['average' => 4.1, 'count' => 12, 'sortable' => 3.2]])]);

    $data = app(OpenLibraryProvider::class)->outsideReviews('OL1W');

    expect($data->reviews)->toBe([])
        ->and($data->rating->score)->toBe(82)
        ->and($data->rating->votes)->toBe(12)
        ->and($data->rating->url)->toBe('https://openlibrary.org/works/OL1W');
});

test('open library with no ratings has no rating, and a bad answer is a failure', function () {
    Http::fake(['openlibrary.org/works/*' => Http::sequence()->push(['summary' => ['average' => null, 'count' => 0]])->push(['nope' => 1])->push('', 404)]);

    expect(app(OpenLibraryProvider::class)->outsideReviews('OL1W')->rating)->toBeNull()
        ->and(fn () => app(OpenLibraryProvider::class)->outsideReviews('OL1W'))->toThrow(ProviderUnavailable::class)
        ->and(fn () => app(OpenLibraryProvider::class)->outsideReviews('OL1W'))->toThrow(ProviderUnavailable::class);
});

test('mangaupdates gives the Bayesian 0-10 rating as 0-100 with its votes and no review text', function () {
    Http::fake(['api.mangaupdates.com/v1/series/55' => Http::response(['series_id' => 55, 'bayesian_rating' => 7.84, 'rating_votes' => 1234, 'url' => 'https://www.mangaupdates.com/series/abc/x'])]);

    $data = app(MangaUpdatesProvider::class)->outsideReviews('55');

    expect($data->reviews)->toBe([])
        ->and($data->rating->score)->toBe(78)
        ->and($data->rating->votes)->toBe(1234)
        ->and($data->rating->url)->toBe('https://www.mangaupdates.com/series/abc/x');
});

test('mangaupdates drops a link off its hosts and fails on a series it does not return', function () {
    Http::fake(['api.mangaupdates.com/v1/series/*' => Http::sequence()->push(['series_id' => 1, 'bayesian_rating' => 6, 'rating_votes' => 0, 'url' => 'https://evil.example/x'])->push(['status' => 'nope'])]);

    $data = app(MangaUpdatesProvider::class)->outsideReviews('1');

    expect($data->rating->url)->toBeNull()->and($data->rating->votes)->toBeNull()

        ->and(fn () => app(MangaUpdatesProvider::class)->outsideReviews('2'))->toThrow(ProviderUnavailable::class);
});
