<?php

use App\Catalog\Data\ProviderRecord;
use App\Catalog\Ingestion\IngestionPipeline;
use App\Catalog\Ingestion\MappedTaxonomy;
use App\Catalog\Ingestion\TaxonomyMapper;
use App\Catalog\Providers\Hardcover\HardcoverProvider;
use App\Catalog\Providers\MissingProviderCredentials;
use App\Catalog\Providers\ProviderUnavailable;
use App\Enums\CreatorRole;
use App\Enums\IngestionMode;
use App\Enums\IngestionStatus;
use App\Jobs\FetchProviderPage;
use App\Models\ExternalSource;
use App\Models\IngestionRun;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['bookflix.providers.hardcover.api_token' => 'tok']));

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function hardcoverBook(int $id = 1, array $overrides = []): array
{
    return [
        'id' => $id,
        'slug' => 'dune',
        'title' => 'Dune',
        'subtitle' => 'Book One',
        'description' => '<p>Arrakis, <i>desert</i> planet.</p>',
        'release_year' => 1965,
        'release_date' => '1965-08-01',
        'pages' => 412,
        'users_count' => 150000,
        'users_read_count' => 90000,
        'rating' => 4.28,
        'ratings_count' => 40000,
        'updated_at' => '2026-10-03T12:00:00',
        'image' => ['url' => 'https://assets.hardcover.app/books/1/dune.jpg'],
        'contributions' => [['contribution' => null, 'author' => ['name' => 'Frank Herbert']], ['contribution' => 'Illustrator', 'author' => ['name' => 'John Schoenherr']], ['contribution' => 'Narrator', 'author' => ['name' => 'Scott Brick']]],
        'cached_tags' => [
            'Genre' => [['tag' => 'Fantasy', 'count' => 30], ['tag' => 'Science Fiction', 'count' => 90]],
            'Mood' => [['tag' => 'Adventurous', 'count' => 5]],
            'Content Warning' => [['tag' => 'Violence', 'count' => 2]],
            'Tag' => [['tag' => 'Space Opera', 'count' => 4]],
        ],
        'book_series' => [['position' => 1, 'series' => ['name' => 'Dune']]],
        'editions' => [['isbn_13' => '978-0441172719']],
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $books
 */
function hardcoverFake(array $books): void
{
    Http::swap(new Factory);
    Http::fake(['api.hardcover.app/*' => Http::response(['data' => ['books' => $books]])]);
}

test('page maps a book to a novel with creators, series, taxonomy, isbn, metrics and urls', function () {
    hardcoverFake([hardcoverBook()]);

    $page = app(HardcoverProvider::class)->page(null, null);
    $media = app(HardcoverProvider::class)->normalize($page->records[0]);

    expect($page->records[0]->externalId)->toBe('1')
        ->and($media->title)->toBe('Dune')->and($media->mediaTypeSlug)->toBe('novel')
        ->and($media->description)->toBe('Arrakis, desert planet.')
        ->and(collect($media->creators)->map(fn ($c) => [$c->name, $c->role])->all())->toBe([['Frank Herbert', CreatorRole::Author], ['John Schoenherr', CreatorRole::Illustrator]])
        ->and($media->series)->toBe(['title' => 'Dune', 'position' => '1'])
        ->and($media->isbns)->toBe(['9780441172719'])
        ->and($media->publicationYear)->toBe(1965)->and($media->publishedOn->toDateString())->toBe('1965-08-01')->and($media->pageCount)->toBe(412)
        ->and($media->coverUrl)->toBe('https://assets.hardcover.app/books/1/dune.jpg')
        ->and($media->sourceUrl)->toBe('https://hardcover.app/books/dune')
        ->and($media->contentWarnings)->toBe(['Violence'])
        ->and($media->metrics)->toBe(['popularity' => 150000, 'follows' => 90000, 'ratings_count' => 40000, 'average_rating' => 4.28])
        ->and(app(TaxonomyMapper::class)->map('hardcover', $media))->toEqual(new MappedTaxonomy(genres: ['science-fiction', 'fantasy', 'space-opera'], warnings: ['violence'], tags: ['adventurous' => 'Adventurous']))
        ->and($page->maxUpdatedAt->toIso8601String())->toBe('2026-10-03T12:00:00+00:00');
});

test('a full request is popularity ordered with bearer auth, accepting a token that already has the prefix', function (string $token) {
    config(['bookflix.providers.hardcover.api_token' => $token]);
    hardcoverFake([]);

    app(HardcoverProvider::class)->page(null, '100');

    Http::assertSent(fn ($r) => $r->url() === 'https://api.hardcover.app/v1/graphql'
        && $r->header('authorization') === ['Bearer tok']
        && $r->header('User-Agent')[0] === config('bookflix.provider_user_agent')
        && str_contains($r['query'], 'order_by: {users_count: desc}') && ! str_contains($r['query'], '_gt')
        && $r['variables'] === ['limit' => 50, 'offset' => 100]);
})->with(['tok', 'Bearer tok', 'bearer  tok']);

test('an incremental request filters updated_at and orders newest first', function () {
    hardcoverFake([]);

    app(HardcoverProvider::class)->page(now()->utc()->setDate(2026, 10, 1)->setTime(8, 30, 5)->toImmutable(), null);

    Http::assertSent(fn ($r) => str_contains($r['query'], 'updated_at: {_gt: $since}') && str_contains($r['query'], 'order_by: {updated_at: desc}')
        && $r['variables']['since'] === '2026-10-01T08:30:05Z' && $r['variables']['offset'] === 0);
});

test('a full page continues at the next offset and a short or empty page ends', function () {
    hardcoverFake(array_map(fn (int $i) => hardcoverBook($i), range(1, 50)));
    expect(app(HardcoverProvider::class)->page(null, '50')->nextPageToken)->toBe('100');

    hardcoverFake([hardcoverBook()]);
    expect(app(HardcoverProvider::class)->page(null, '50')->nextPageToken)->toBeNull();

    hardcoverFake([]);
    expect(app(HardcoverProvider::class)->page(null, '50')->nextPageToken)->toBeNull();
});

test('find queries one book by id and returns null when absent', function () {
    hardcoverFake([hardcoverBook(9)]);
    expect(app(HardcoverProvider::class)->find('9')->externalId)->toBe('9');
    Http::assertSent(fn ($r) => $r['variables'] === ['id' => 9]);

    hardcoverFake([]);
    expect(app(HardcoverProvider::class)->find('10'))->toBeNull();
});

test('graphic novel, comic and manga tags change the media type once the rest of the book agrees', function (array $genres, array $overrides, string $slug) {
    $book = hardcoverBook(1, ['cached_tags' => ['Genre' => array_map(fn (string $tag) => ['tag' => $tag, 'count' => 1], $genres)], ...$overrides]);

    expect(app(HardcoverProvider::class)->normalize(new ProviderRecord('hardcover', '1', $book, null, now()->toImmutable()))->mediaTypeSlug)->toBe($slug);
})->with([
    'a graphic novel' => [['Graphic Novels'], ['pages' => 192, 'contributions' => [['contribution' => null, 'author' => ['name' => 'Brian K. Vaughan']], ['contribution' => 'Colorist', 'author' => ['name' => 'Fiona Staples']]]], 'graphic-novel'],
    'comics are bound books here' => [['Comics'], ['pages' => null, 'contributions' => []], 'graphic-novel'],
    'a single issue' => [['Comics'], ['pages' => 32, 'contributions' => []], 'comic-book'],
    'manga' => [['Manga', 'Fantasy'], ['pages' => 224, 'contributions' => []], 'manga'],
    'a novel' => [['Fantasy'], [], 'novel'],
    'a novel filed under Graphic Novels' => [['Graphic Novels', 'Fantasy'], ['title' => 'Batman: Fear Itself', 'pages' => 380, 'contributions' => [['contribution' => null, 'author' => ['name' => 'Michael Reaves']]]], 'novel'],
    'a light novel' => [['Light Novels', 'Fantasy'], ['pages' => 260], 'light-novel'],
    'a visual novel' => [['Visual Novel'], ['pages' => null, 'contributions' => []], 'visual-novel'],
]);

/**
 * A Hardcover book whose genres carry these reader votes, `['Classics' => 17, 'Comics' => 1]`.
 *
 * @param  array<string, int>  $genres
 * @param  array<string, mixed>  $overrides
 */
function votedBook(array $genres, array $overrides = []): ProviderRecord
{
    $tags = array_map(fn (string $tag, int $count) => ['tag' => $tag, 'count' => $count], array_keys($genres), $genres);

    return new ProviderRecord('hardcover', '1', hardcoverBook(1, ['cached_tags' => ['Genre' => $tags], 'contributions' => [['contribution' => 'Author', 'author' => ['name' => 'A']]], ...$overrides]), null, now()->toImmutable());
}

test('a genre almost no reader gave is dropped, so one vote for Comics does not make a classic a comic', function (array $genres, array $overrides, string $slug) {
    expect(app(HardcoverProvider::class)->normalize(votedBook($genres, $overrides))->mediaTypeSlug)->toBe($slug);
})->with([
    'The Great Gatsby' => [['Classics' => 17, 'Literary Fiction' => 4, 'Romance' => 3, 'Literature & Fiction' => 2, 'Young Adult' => 2, 'Literature' => 1, 'Comics' => 1, 'Man-woman relationships' => 1, 'Biography' => 1, 'Spanish' => 1], ['title' => 'The Great Gatsby', 'pages' => 180], 'novel'],
    'Of Mice and Men' => [['Classics' => 9, 'Historical Fiction' => 3, 'Literary Fiction' => 2, 'Small Town & Rural' => 2, 'Novella' => 2, 'American literature' => 1, 'Comics' => 1, 'Cowboys' => 1], ['title' => 'Of Mice and Men', 'subtitle' => 'A Play in Three Acts', 'pages' => 162], 'novel'],
    'Normal People' => [['Romance' => 8, 'Literary Fiction' => 6, 'Young Adult' => 3, 'Contemporary' => 2, 'Psychological' => 1, 'Fantasy' => 1, 'Manga' => 1, 'Comics' => 1], ['title' => 'Normal People', 'subtitle' => 'A Novel', 'pages' => 273], 'novel'],
    'Les Misérables' => [['Classics' => 8, 'History' => 2, 'Graphic Novels' => 2, 'Comics' => 1, 'Literature' => 1, 'Police' => 1], ['title' => 'Les Misérables', 'subtitle' => 'Volume 1', 'pages' => 1458], 'novel'],
    'New Moon' => [['Fantasy' => 8, 'Romance' => 8, 'Juvenile Fiction' => 2, 'General' => 2, 'Graphic Novels' => 1, 'Young Adult' => 1, 'Comics' => 1], ['title' => 'New Moon', 'subtitle' => 'The Graphic Novel', 'pages' => 497], 'novel'],
    'Heartstopper' => [['LGBTQ' => 8, 'Young Adult' => 6, 'Romance' => 5, 'Graphic Novels' => 5, 'Comics' => 2, 'Lgbt' => 2, 'Boys' => 1], ['title' => 'Heartstopper: Volume One', 'subtitle' => 'Volume One', 'pages' => 263], 'graphic-novel'],
    'Maus' => [['Graphic Novels' => 4, 'History' => 2, 'General' => 1, 'Biography' => 1, 'Comics' => 1, 'War' => 1], ['title' => 'Maus', 'subtitle' => "A Survivor's Tale", 'pages' => 295], 'graphic-novel'],
    'every tag at one vote keeps them all' => [['Fantasy' => 1, 'Dystopian' => 1, 'Comics' => 1, 'Adventure' => 1], ['title' => 'Tower of God', 'pages' => 288], 'graphic-novel'],
    'Atomic Habits' => [['Nonfiction' => 40, 'Self Help' => 30, 'Business' => 12, 'Psychology' => 10, 'Personal Development' => 9, 'Fantasy' => 1], ['title' => 'Atomic Habits', 'pages' => 320], 'nonfiction'],
]);

test('the dropped genres are not stored either', function () {
    $media = app(HardcoverProvider::class)->normalize(votedBook(['Classics' => 17, 'Literary Fiction' => 5, 'Comics' => 1, 'Biography' => 1]));

    expect($media->genres)->toBe(['Classics', 'Literary Fiction']);
});

test('missing credentials fail readably without a request and the run ends failed', function () {
    config(['bookflix.providers.hardcover.api_token' => null]);
    Http::fake();

    expect(fn () => app(HardcoverProvider::class)->page(null, null))->toThrow(MissingProviderCredentials::class, 'BOOKFLIX_HARDCOVER_API_TOKEN');
    Http::assertNothingSent();

    $source = ExternalSource::factory()->create(['key' => 'hardcover', 'enabled' => true]);
    $run = IngestionRun::create(['external_source_id' => $source->id, 'mode' => IngestionMode::Full, 'started_at' => now()]);
    (new FetchProviderPage($run->id))->handle(app(IngestionPipeline::class));

    expect($run->fresh()->status)->toBe(IngestionStatus::Failed)->and($run->fresh()->error)->toContain('BOOKFLIX_HARDCOVER_API_TOKEN');
});

test('graphql errors without data surface as unavailable', function () {
    Http::fake(['api.hardcover.app/*' => Http::response(['errors' => [['message' => 'Invalid token']]])]);

    expect(fn () => app(HardcoverProvider::class)->page(null, null))->toThrow(ProviderUnavailable::class, 'Invalid token');
});

test('normalize tolerates shape drift and rejects off-host urls', function () {
    $media = app(HardcoverProvider::class)->normalize(new ProviderRecord('hardcover', '1', [
        'title' => 7, 'description' => [], 'release_year' => '1999', 'release_date' => 'garbage', 'pages' => 'many',
        'rating' => 'high', 'users_count' => '5', 'image' => ['url' => 'https://evil.test/a.jpg'], 'contributions' => 'x',
        'cached_tags' => 'none', 'book_series' => [['series' => 'x']], 'editions' => [['isbn_13' => 'abc'], 'x'],
    ], 'http://evil.test/', now()->toImmutable()));

    expect($media->title)->toBe('')->and($media->mediaTypeSlug)->toBe('novel')->and($media->description)->toBeNull()
        ->and($media->creators)->toBe([])->and($media->series)->toBeNull()->and($media->isbns)->toBe([])
        ->and($media->genres)->toBe([])->and($media->publicationYear)->toBeNull()->and($media->publishedOn)->toBeNull()
        ->and($media->pageCount)->toBeNull()->and($media->metrics)->toBe([])->and($media->coverUrl)->toBeNull()->and($media->sourceUrl)->toBeNull();
});
