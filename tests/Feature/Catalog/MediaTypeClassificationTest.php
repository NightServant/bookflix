<?php

use App\Catalog\Data\ProviderRecord;
use App\Catalog\Ingestion\IngestionPipeline;
use App\Catalog\Providers\ComicVine\ComicVineProvider;
use App\Catalog\Providers\Metron\MetronProvider;
use App\Catalog\Providers\OpenLibrary\OpenLibraryProvider;
use App\Enums\ImportOutcome;
use App\Models\ExternalSource;
use App\Models\Media;
use App\Models\MediaType;
use Database\Seeders\MediaTypeSeeder;

/**
 * @param  array<string, mixed>  $payload
 */
function comicVineType(array $payload): string
{
    return app(ComicVineProvider::class)->normalize(new ProviderRecord('comicvine', '1', ['id' => 1, 'start_year' => '2010', 'count_of_issues' => 1, ...$payload], null, now()->toImmutable()))->mediaTypeSlug;
}

/**
 * @param  array<string, mixed>  $work
 * @param  list<array<string, mixed>>  $editions
 */
function openLibraryType(array $work, array $editions = []): string
{
    $payload = ['work' => ['title' => 'Untitled', ...$work], 'editions' => $editions, 'authors' => ['Someone']];

    return app(OpenLibraryProvider::class)->normalize(new ProviderRecord('openlibrary', 'OL1W', $payload, null, now()->toImmutable()))->mediaTypeSlug;
}

test('Comic Vine volumes that are prose are novels, the rest comic books', function (array $payload, string $slug) {
    expect(comicVineType($payload))->toBe($slug);
})->with([
    'Batman: The Novel' => [['name' => 'Batman: The Novel', 'deck' => 'A prose novel starring the Dark Knight.', 'publisher' => ['name' => 'DC Comics']], 'novel'],
    'a deck that says novelization' => [['name' => 'Iron Man 2', 'deck' => 'The novelization of the film.', 'publisher' => ['name' => 'Marvel']], 'novel'],
    'a prose publisher and an opening blurb' => [['name' => 'Star Wars: Thrawn', 'deck' => 'A novel set after the Clone Wars.', 'publisher' => ['name' => 'Del Rey'], 'count_of_issues' => 1], 'novel'],
    'a light novel' => [['name' => 'Sword Art Online: Aincrad (Light Novel)', 'publisher' => ['name' => 'Yen On']], 'light-novel'],
    'an ordinary series' => [['name' => 'Batman', 'deck' => 'The Dark Knight.', 'publisher' => ['name' => 'DC Comics'], 'count_of_issues' => 713], 'comic-book'],
    'a series with no deck at all' => [['name' => 'Untitled Project'], 'comic-book'],
]);

test('Metron series follow their series type, and a prose hardcover is not a graphic novel', function (array $detail, string $slug) {
    $record = new ProviderRecord('metron', '1', ['id' => 1, 'name' => 'Batman', 'issue_count' => 12, 'publisher' => ['name' => 'DC Comics'], ...$detail], null, now()->toImmutable());

    expect(app(MetronProvider::class)->normalize($record)->mediaTypeSlug)->toBe($slug);
})->with([
    'a trade paperback' => [['series_type' => ['name' => 'Trade Paperback'], 'name' => 'Batman: Year One'], 'graphic-novel'],
    'a hardcover' => [['series_type' => ['name' => 'Hardcover'], 'name' => 'Absolute Batman'], 'graphic-novel'],
    'an ongoing series' => [['series_type' => ['name' => 'Ongoing Series']], 'comic-book'],
    'a novel in hardcover' => [['series_type' => ['name' => 'Hardcover'], 'name' => 'Batman: Dead White (Novel)', 'publisher' => ['name' => 'Titan Books']], 'novel'],
    'a novelization blurb' => [['series_type' => ['name' => 'Trade Paperback'], 'name' => 'Batman Begins', 'desc' => '<p>The novelization of the film by Dennis O\'Neil.</p>', 'publisher' => ['name' => 'Bantam']], 'novel'],
]);

test('Open Library tells a novel adapted from a comic from the comic itself', function (array $work, array $editions, string $slug) {
    expect(openLibraryType($work, $editions))->toBe($slug);
})->with([
    'a prose tie-in filed as a graphic novel' => [
        ['title' => "Batman: No Man's Land", 'subjects' => ['Comic books, strips, etc.', 'Graphic novels', 'Fiction']],
        [['publishers' => ['Pocket Books'], 'number_of_pages' => 420, 'publish_date' => '1999']],
        'novel',
    ],
    'a real graphic novel' => [
        ['title' => 'Watchmen', 'subjects' => ['Comic books, strips, etc.', 'Graphic novels', 'Superheroes']],
        [['publishers' => ['DC Comics'], 'number_of_pages' => 416, 'publish_date' => '1987']],
        'graphic-novel',
    ],
    'a manga volume' => [
        ['title' => 'Naruto, Vol. 1', 'subjects' => ['Manga', 'Comic books, strips, etc.']],
        [['publishers' => ['Viz Media'], 'number_of_pages' => 192, 'publish_date' => '2003']],
        'manga',
    ],
    'a light novel from Yen On' => [
        ['title' => 'Sword Art Online, Vol. 1', 'subjects' => ['Fantasy fiction', 'Video games']],
        [['publishers' => ['Yen On'], 'number_of_pages' => 240, 'publish_date' => '2014']],
        'light-novel',
    ],
    'a plain novel' => [
        ['title' => 'Dune', 'subjects' => ['Fiction', 'Science fiction']],
        [['publishers' => ['Ace Books'], 'number_of_pages' => 412, 'publish_date' => '1990']],
        'novel',
    ],
    'a picture book' => [
        ['title' => 'Goodnight Moon', 'subjects' => ['Picture books', 'Juvenile fiction']],
        [['publishers' => ['HarperCollins'], 'number_of_pages' => 32, 'publish_date' => '1947']],
        'picture-book',
    ],
    'a visual novel' => [['title' => 'Clannad', 'subjects' => ['Visual novels', 'Video games']], [], 'visual-novel'],
    'no subjects at all' => [['title' => 'Untitled'], [], 'novel'],
]);

test('a comic publisher on a later edition still counts', function () {
    expect(openLibraryType(
        ['title' => 'Maus', 'subjects' => ['Graphic novels']],
        [['publishers' => ['Pantheon'], 'number_of_pages' => 296, 'publish_date' => '1986'], ['publishers' => ['Dark Horse Comics'], 'publish_date' => '2003']],
    ))->toBe('graphic-novel');
});

/**
 * @param  array<string, mixed>  $payload
 */
function importComicVine(array $payload): ImportOutcome
{
    app(MediaTypeSeeder::class)->run();
    ExternalSource::where('key', 'comicvine')->exists() || ExternalSource::factory()->create(['key' => 'comicvine']);

    $payload = ['id' => 77, 'name' => 'Batman', 'publisher' => ['name' => 'DC Comics'], 'start_year' => '2010', 'count_of_issues' => 3, ...$payload];

    return app(IngestionPipeline::class)->import(new ProviderRecord('comicvine', '77', $payload, null, now()->toImmutable()), app(ComicVineProvider::class));
}

test('a re-sync corrects a wrong media type, unless an admin locked it', function () {
    importComicVine(['deck' => 'The Dark Knight.']);
    expect(Media::sole()->mediaType->slug)->toBe('comic-book');

    importComicVine(['name' => 'Batman: The Novel', 'deck' => 'The Dark Knight, in prose.']);
    expect(Media::sole()->mediaType->slug)->toBe('novel');

    Media::sole()->update(['media_type_id' => MediaType::where('slug', 'graphic-novel')->value('id'), 'locked_fields' => ['media_type_id']]);
    importComicVine(['name' => 'Batman: The Novel', 'deck' => 'Prose again.', 'count_of_issues' => 4]);

    expect(Media::sole()->mediaType->slug)->toBe('graphic-novel');
});

test('a provider that does not own the media cannot change its type', function () {
    importComicVine(['name' => 'Batman: The Novel']);
    $media = Media::sole();
    $media->update(['primary_external_source_id' => ExternalSource::factory()->create(['key' => 'metron'])->id]);

    importComicVine(['name' => 'Batman', 'deck' => 'A comic.', 'count_of_issues' => 9]);

    expect($media->fresh()->mediaType->slug)->toBe('novel');
});
