<?php

use App\Discovery\Search\HybridSearch;
use App\Discovery\Search\SearchFilters;
use App\Discovery\Search\TaxonomyFacets;
use App\Enums\AgeRating;
use App\Models\ContentWarning;
use App\Models\Demographic;
use App\Models\Genre;
use App\Models\Media;
use App\Models\Theme;
use App\Models\Trope;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Titles of a browse, sorted.
 *
 * @param  array<string, mixed>  $input
 * @return list<string>
 */
function browsed(array $input): array
{
    return app(HybridSearch::class)->browse(SearchFilters::fromArray($input))->pluck('title')->sort()->values()->all();
}

/**
 * A small catalogue on the seeded taxonomy: each title has the labels its name says.
 */
function taxonomyCatalog(): void
{
    test()->seed(TaxonomySeeder::class);
    $genre = fn (string $slug) => Genre::where('slug', $slug)->firstOrFail();
    $make = function (string $title, array $genres = [], array $themes = [], array $tropes = [], array $demographics = [], AgeRating $rating = AgeRating::Unknown) use ($genre): Media {
        $media = Media::factory()->create(['title' => $title, 'age_rating' => $rating]);
        $media->genres()->attach(array_map(fn (string $slug) => $genre($slug)->id, $genres));
        $media->themes()->attach(Theme::whereIn('slug', $themes)->pluck('id'));
        $media->tropes()->attach(Trope::whereIn('slug', $tropes)->pluck('id'));
        $media->demographics()->attach(Demographic::whereIn('slug', $demographics)->pluck('id'));

        return $media;
    };

    $make('Isekai Hero', ['isekai'], ['survival'], ['chosen-one', 'overpowered-protagonist'], ['shonen'], AgeRating::Teen);
    $make('Litrpg Grind', ['litrpg'], [], ['leveling-up'], ['seinen'], AgeRating::MatureTeen);
    $make('Plain Fantasy', ['fantasy'], ['war'], [], [], AgeRating::All);
    $make('Courtroom', ['courtroom-drama'], ['justice'], [], ['seinen'], AgeRating::Mature);
    $make('Slow Romance', ['slow-burn', 'contemporary-romance'], [], ['slow-burn'], ['josei'], AgeRating::Teen);
    $make('Untouched');
}

test('a family includes its subgenres, a subgenre only itself, and a subgenre listed under two families is found under both', function () {
    taxonomyCatalog();

    expect(browsed(['genres' => ['fantasy']]))->toBe(['Isekai Hero', 'Litrpg Grind', 'Plain Fantasy'])
        ->and(browsed(['genres' => ['isekai']]))->toBe(['Isekai Hero'])
        ->and(browsed(['genres' => ['drama-and-human-relationships']]))->toBe(['Courtroom'])
        ->and(browsed(['genres' => ['mystery-crime-and-thriller']]))->toBe(['Courtroom'])
        ->and(browsed(['genres' => ['romance']]))->toBe(['Slow Romance'])
        ->and(browsed(['genres' => ['isekai', 'romance']]))->toBe(['Isekai Hero', 'Slow Romance']);
});

test('several values of one group mean any of them, different groups all have to hold', function () {
    taxonomyCatalog();

    expect(browsed(['genres' => ['isekai', 'litrpg'], 'demographics' => ['shonen']]))->toBe(['Isekai Hero'])
        ->and(browsed(['genres' => ['fantasy'], 'demographics' => ['shonen', 'seinen']]))->toBe(['Isekai Hero', 'Litrpg Grind'])
        ->and(browsed(['genres' => ['fantasy'], 'themes' => ['survival', 'war']]))->toBe(['Isekai Hero', 'Plain Fantasy'])
        ->and(browsed(['genres' => ['fantasy'], 'themes' => ['survival'], 'tropes' => ['chosen-one']]))->toBe(['Isekai Hero'])
        ->and(browsed(['genres' => ['fantasy'], 'themes' => ['survival'], 'tropes' => ['leveling-up']]))->toBe([]);
});

test('tropes, demographics and audience ratings each filter on their own', function () {
    taxonomyCatalog();

    expect(browsed(['tropes' => ['chosen-one', 'leveling-up']]))->toBe(['Isekai Hero', 'Litrpg Grind'])
        ->and(browsed(['tropes' => ['slow-burn']]))->toBe(['Slow Romance'])
        ->and(browsed(['demographics' => ['seinen']]))->toBe(['Courtroom', 'Litrpg Grind'])
        ->and(browsed(['demographics' => ['josei']]))->toBe(['Slow Romance'])
        ->and(browsed(['ratings' => ['teen']]))->toBe(['Isekai Hero', 'Slow Romance'])
        ->and(browsed(['ratings' => ['teen', 'mature_teen']]))->toBe(['Isekai Hero', 'Litrpg Grind', 'Slow Romance'])
        ->and(browsed(['ratings' => ['all'], 'demographics' => ['seinen']]))->toBe([]);
});

test('the demographic is not the audience rating', function () {
    taxonomyCatalog();

    // Shonen (editorial) is on a work rated teen here, but a mature rated seinen work is not teen: neither filter implies the other.
    expect(browsed(['demographics' => ['shonen'], 'ratings' => ['teen']]))->toBe(['Isekai Hero'])
        ->and(browsed(['demographics' => ['seinen'], 'ratings' => ['teen']]))->toBe([]);
});

test('unknown is not a rating you can filter by, and invalid values are dropped', function () {
    $filters = SearchFilters::fromArray(['ratings' => ['unknown', 'bogus', 'adult', 'mature_teen'], 'excludeWarnings' => 'gore']);

    expect($filters->ratings)->toBe(['adult', 'mature_teen'])->and($filters->excludeWarnings)->toBe(['gore']);
});

test('excluding a warning removes works where it is present or disputed and keeps unknown, absent and unrecorded ones', function () {
    taxonomyCatalog();
    $gore = ContentWarning::where('slug', 'gore')->firstOrFail();
    $violence = ContentWarning::where('slug', 'violence')->firstOrFail();

    Media::where('title', 'Isekai Hero')->first()->warnings()->attach($gore, ['status' => 'present']);
    Media::where('title', 'Litrpg Grind')->first()->warnings()->attach($gore, ['status' => 'disputed']);
    Media::where('title', 'Plain Fantasy')->first()->warnings()->attach($gore, ['status' => 'absent']);
    Media::where('title', 'Courtroom')->first()->warnings()->attach($gore, ['status' => 'unknown']);
    Media::where('title', 'Slow Romance')->first()->warnings()->attach($violence, ['status' => 'present']);

    expect(browsed(['excludeWarnings' => ['gore']]))->toBe(['Courtroom', 'Plain Fantasy', 'Slow Romance', 'Untouched'])
        ->and(browsed(['excludeWarnings' => ['gore', 'violence']]))->toBe(['Courtroom', 'Plain Fantasy', 'Untouched'])
        ->and(browsed(['excludeWarnings' => ['no-such-warning']]))->toHaveCount(6)
        ->and(browsed(['genres' => ['fantasy'], 'excludeWarnings' => ['gore']]))->toBe(['Plain Fantasy']);
});

test('the new filters narrow a text search too', function () {
    taxonomyCatalog();
    Media::query()->update(['search_text' => 'story']);
    $gore = ContentWarning::where('slug', 'gore')->firstOrFail();
    Media::where('title', 'Isekai Hero')->first()->warnings()->attach($gore, ['status' => 'present']);

    $titles = fn (SearchFilters $filters) => app(HybridSearch::class)->search('story', $filters, null)->pluck('title')->sort()->values()->all();

    expect($titles(new SearchFilters(genres: ['fantasy'])))->toBe(['Isekai Hero', 'Litrpg Grind', 'Plain Fantasy'])
        ->and($titles(new SearchFilters(genres: ['fantasy'], excludeWarnings: ['gore'])))->toBe(['Litrpg Grind', 'Plain Fantasy'])
        ->and($titles(new SearchFilters(genres: ['fantasy'], tropes: ['leveling-up'])))->toBe(['Litrpg Grind']);
});

test('the filters round trip through toArray and fromArray', function () {
    $filters = new SearchFilters(genres: ['fantasy'], tropes: ['chosen-one'], demographics: ['shonen'], ratings: ['teen'], excludeWarnings: ['gore']);

    expect(SearchFilters::fromArray($filters->toArray()))->toEqual($filters)
        ->and($filters->isEmpty())->toBeFalse();
});

test('a crafted payload cannot flood the new lists either', function () {
    $filters = SearchFilters::fromArray(['tropes' => range(1, 500), 'demographics' => range(1, 500), 'excludeWarnings' => range(1, 500), 'genres' => range(1, 500)]);

    expect($filters->tropes)->toHaveCount(SearchFilters::MAX_VALUES)->and($filters->demographics)->toHaveCount(SearchFilters::MAX_VALUES)
        ->and($filters->excludeWarnings)->toHaveCount(SearchFilters::MAX_VALUES)->and($filters->genres)->toHaveCount(SearchFilters::MAX_VALUES);
});

test('every taxonomy filter at once is one statement per leg, not one per value', function () {
    taxonomyCatalog();
    DB::enableQueryLog();

    browsed(['genres' => ['fantasy', 'romance', 'isekai'], 'themes' => ['war', 'survival'], 'tropes' => ['chosen-one'], 'demographics' => ['shonen', 'seinen'], 'ratings' => ['teen'], 'excludeWarnings' => ['gore', 'violence']]);

    // the page of media, the total, and nothing per selected value
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(3);
});

test('the genre tree filter is served by indexes', function () {
    taxonomyCatalog();
    DB::statement('set enable_seqscan = off');

    $explain = collect(DB::select(
        'explain '.str_replace('?', "'{fantasy}'", 'select media.id from media where exists (select 1 from media_genres where media_genres.media_id = media.id and media_genres.genre_id in ('.Genre::TREE_IDS_SQL.'))'),
    ))->map(fn ($row) => implode(' ', (array) $row))->implode("\n");

    DB::statement('set enable_seqscan = on');

    expect($explain)->toContain('media_genres')->and($explain)->not->toContain('Seq Scan on media_genres');
});

test('the facets give the genre tree with groups, linked subgenres under both parents, and hide mature genres unless asked', function () {
    $this->seed(TaxonomySeeder::class);
    $facets = app(TaxonomyFacets::class);

    $public = collect($facets->genres());
    $fantasy = $public->firstWhere('slug', 'fantasy');
    $drama = $public->firstWhere('slug', 'drama-and-human-relationships');
    $everything = collect($facets->genres(true));

    expect($public)->toHaveCount(16)->and($everything)->toHaveCount(17)
        ->and(collect($fantasy['groups'])->pluck('name')->all())->toBe(['High and epic fantasy', 'Progression and Eastern fantasy'])
        ->and(collect($fantasy['groups'][1]['items'])->pluck('slug')->all())->toContain('isekai', 'litrpg')
        ->and(collect($drama['groups'])->flatMap(fn ($group) => collect($group['items'])->pluck('slug'))->all())->toContain('courtroom-drama', 'coming-of-age')
        ->and($public->flatMap(fn ($family) => collect($family['groups'])->flatMap(fn ($group) => collect($group['items'])->pluck('slug')))->all())->not->toContain('erotica')
        ->and($everything->firstWhere('is_mature', true)['slug'])->toBe('mature-erotic-and-controversial-categories')
        ->and(array_keys($facets->demographics()))->toBe(['kodomomuke', 'shonen', 'shojo', 'seinen', 'josei', 'young-adult', 'new-adult', 'adult-literary-audience'])
        ->and(array_keys($facets->ratings()))->toBe(['all', 'children', 'teen', 'mature_teen', 'mature', 'adult'])
        ->and($facets->ratings()['adult'])->toBe('Adult (18+)')
        ->and($facets->warnings())->toHaveCount(30)
        ->and(collect($facets->tropes())->pluck('name')->all())->toBe(['Romance tropes', 'Fantasy and progression tropes', 'Mystery and psychological tropes'])
        ->and($facets->themes())->toHaveKey('found-family');
});

test('the facets are cached as plain arrays and dropped by a re-seed', function () {
    $this->seed(TaxonomySeeder::class);
    $facets = app(TaxonomyFacets::class);
    $facets->genres();
    $facets->themes();

    DB::enableQueryLog();
    $facets->genres();
    $facets->themes();

    expect(DB::getQueryLog())->toBeEmpty()->and(Cache::get('taxonomy:genres:v1:public'))->toBeArray();

    $this->seed(TaxonomySeeder::class);

    expect(Cache::has('taxonomy:genres:v1:public'))->toBeFalse();
});
