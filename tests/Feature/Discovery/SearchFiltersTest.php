<?php

use App\Discovery\Search\HybridSearch;
use App\Discovery\Search\SearchFilters;
use App\Discovery\Search\SearchTextBuilder;
use App\Models\Creator;
use App\Models\Genre;
use App\Models\Media;
use App\Models\Publisher;
use App\Models\Theme;
use Database\Seeders\DeweySeeder;

beforeEach(fn () => $this->seed(DeweySeeder::class));

/**
 * Six media that differ on every filter: a long ongoing Japanese manga, a medium completed English novel, a short story, and three fillers that match nothing.
 */
function seedFilterCatalog(): void
{
    $fantasy = Genre::factory()->create(['name' => 'Fantasy', 'slug' => 'fantasy']);
    $politics = Theme::factory()->create(['name' => 'Politics', 'slug' => 'politics']);

    $berserk = Media::factory()->manga()->create(['title' => 'Berserk', 'language' => 'ja', 'publication_year' => 1990, 'average_rating' => 9.40, 'status' => 'ongoing', 'chapter_count' => 364]);
    $berserk->genres()->attach($fantasy);
    $berserk->creators()->attach(Creator::factory()->create(['name' => 'Kentaro Miura']), ['role' => 'author']);
    $berserk->publishers()->attach(Publisher::factory()->create(['name' => 'Hakusensha']));
    classified($berserk, '741.52');

    $dune = Media::factory()->novel()->create(['title' => 'Dune Fantasy', 'language' => 'en', 'publication_year' => 1965, 'average_rating' => 7.50, 'status' => 'completed', 'page_count' => 412]);
    $dune->genres()->attach($fantasy);
    $dune->themes()->attach($politics);
    $dune->creators()->attach(Creator::factory()->create(['name' => 'Frank Herbert']), ['role' => 'author']);
    $dune->publishers()->attach(Publisher::factory()->create(['name' => 'Ace']));
    classified($dune, '813');

    Media::factory()->novel()->create(['title' => 'Short Story', 'language' => 'en', 'publication_year' => 2010, 'average_rating' => 6.00, 'status' => 'unknown', 'page_count' => 80]);
    Media::factory()->novel()->create(['title' => 'Quiet Novel', 'language' => 'en', 'publication_year' => 2020, 'average_rating' => 5.00, 'status' => 'ongoing', 'page_count' => 300]);
    Media::factory()->create(['title' => 'Picture Tale', 'language' => 'fr', 'publication_year' => 2005, 'status' => 'hiatus']);
    Media::factory()->create(['title' => 'Old Comic', 'language' => 'en', 'publication_year' => 1980, 'status' => 'cancelled']);
}

test('each filter narrows results', function (array $input, array $expectedTitles) {
    seedFilterCatalog();

    if (isset($input['creatorName'])) {
        $input['creatorIds'] = Creator::where('name', $input['creatorName'])->pluck('id')->all();
    }

    if (isset($input['publisherName'])) {
        $input['publisherIds'] = Publisher::where('name', $input['publisherName'])->pluck('id')->all();
    }

    $titles = app(HybridSearch::class)->browse(SearchFilters::fromArray($input))->pluck('title')->sort()->values()->all();

    expect($titles)->toBe($expectedTitles);
})->with([
    'type' => [['mediaTypes' => ['manga']], ['Berserk']],
    'genre' => [['genres' => ['fantasy']], ['Berserk', 'Dune Fantasy']],
    'theme' => [['themes' => ['politics']], ['Dune Fantasy']],
    'language' => [['languages' => ['ja']], ['Berserk']],
    'year range' => [['yearFrom' => 1990, 'yearTo' => 2000], ['Berserk']],
    'year from only' => [['yearFrom' => 2005], ['Picture Tale', 'Quiet Novel', 'Short Story']],
    'rating' => [['minRating' => 8.0], ['Berserk']],
    'status' => [['statuses' => ['completed']], ['Dune Fantasy']],
    'length short' => [['length' => 'short'], ['Short Story']],
    'length medium' => [['length' => 'medium'], ['Dune Fantasy', 'Quiet Novel']],
    'length chapters' => [['length' => 'long'], ['Berserk']],
    'creator' => [['creatorName' => 'Kentaro Miura'], ['Berserk']],
    'publisher' => [['publisherName' => 'Ace'], ['Dune Fantasy']],
    'dewey subtree' => [['deweyCode' => '810'], ['Dune Fantasy']],
    'unknown dewey code' => [['deweyCode' => '999.9'], []],
    'several values mean any of them' => [['mediaTypes' => ['manga', 'novel'], 'statuses' => ['ongoing', 'completed']], ['Berserk', 'Dune Fantasy', 'Quiet Novel']],
]);

test('filters combine with a text query', function () {
    seedFilterCatalog();
    Media::query()->each(fn (Media $media) => $media->update(['search_text' => app(SearchTextBuilder::class)->build($media)]));

    $novels = app(HybridSearch::class)->search('berserk', new SearchFilters(mediaTypes: ['novel']), null);
    $manga = app(HybridSearch::class)->search('berserk', new SearchFilters(mediaTypes: ['manga']), null);

    expect($novels->total())->toBe(0)
        ->and($manga->pluck('title')->all())->toBe(['Berserk']);
});

test('filters narrow the subject leg too', function () {
    seedFilterCatalog();

    expect(search('literature')->pluck('title')->all())->toBe(['Dune Fantasy'])
        ->and(search('literature', new SearchFilters(languages: ['ja']))->total())->toBe(0);
});

test('browse with no filters lists every live media, most rated first', function () {
    seedFilterCatalog();
    Media::where('title', 'Quiet Novel')->update(['ratings_count' => 10]);
    Media::where('title', 'Old Comic')->delete();

    $titles = app(HybridSearch::class)->browse(new SearchFilters)->pluck('title');

    expect($titles->first())->toBe('Quiet Novel')
        ->and($titles)->toHaveCount(5)
        ->and($titles)->not->toContain('Old Comic');
});

test('fromArray drops unknown keys and invalid values', function () {
    $f = SearchFilters::fromArray(['mediaTypes' => 'manga', 'yearFrom' => 'abc', 'statuses' => ['bogus', 'ongoing'], 'length' => 'huge', 'creatorIds' => ['3', 'x'], 'evil' => 1]);

    expect($f->mediaTypes)->toBe(['manga'])
        ->and($f->yearFrom)->toBeNull()
        ->and($f->statuses)->toBe(['ongoing'])
        ->and($f->length)->toBeNull()
        ->and($f->creatorIds)->toBe([3]);
});

test('toArray lists only what is set and round trips through fromArray', function () {
    $filters = new SearchFilters(genres: ['fantasy'], minRating: 7.5, length: 'short');

    expect($filters->toArray())->toBe(['genres' => ['fantasy'], 'minRating' => 7.5, 'length' => 'short'])
        ->and(SearchFilters::fromArray($filters->toArray()))->toEqual($filters)
        ->and($filters->isEmpty())->toBeFalse()
        ->and((new SearchFilters)->isEmpty())->toBeTrue();
});

test('rejected or low confidence dewey rows do not satisfy a dewey filter', function (string $status, float $confidence) {
    classified(Media::factory()->create(['title' => 'Hidden']), '813', status: $status, confidence: $confidence);

    expect(app(HybridSearch::class)->browse(new SearchFilters(deweyCode: '810'))->pluck('title'))->not->toContain('Hidden');
})->with([
    'rejected' => ['rejected', 0.95],
    'low confidence' => ['pending', 0.5],
]);

test('a secondary class does not satisfy a dewey filter', function () {
    classified(Media::factory()->create(['title' => 'Hidden']), '813', 'secondary');

    expect(app(HybridSearch::class)->browse(new SearchFilters(deweyCode: '810'))->pluck('title'))->not->toContain('Hidden');
});

test('a crafted payload cannot flood a list filter past the cap', function () {
    $filters = SearchFilters::fromArray(['genres' => range(1, 900), 'creatorIds' => range(1, 500)]);

    // The genre tree has about six hundred nodes, so a reader may pick that many; anything past it is dropped.
    expect($filters->genres)->toHaveCount(SearchFilters::MAX_GENRES)->and($filters->creatorIds)->toHaveCount(SearchFilters::MAX_VALUES);
});
