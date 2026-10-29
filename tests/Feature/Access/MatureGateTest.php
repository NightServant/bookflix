<?php

use App\Access\MatureAccess;
use App\Classification\DeweyCounts;
use App\Discovery\HomeShelves;
use App\Discovery\Search\HybridSearch;
use App\Discovery\Search\SearchFilters;
use App\Discovery\Search\TaxonomyFacets;
use App\Discovery\SimilarMedia;
use App\Models\Genre;
use App\Models\Media;
use App\Models\Series;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * A small catalogue with one title of each kind the access gate cares about, all matching the word "tale".
 *
 * @return array<string, Media>
 */
function gatedCatalog(): array
{
    test()->seed(TaxonomySeeder::class);
    $make = function (string $title, array $attributes = [], array $genres = []): Media {
        $media = Media::factory()->manga()->create(['title' => $title, 'search_text' => strtolower($title), ...$attributes]);
        $media->genres()->attach(Genre::whereIn('slug', $genres)->pluck('id'));

        return $media;
    };

    return [
        'plain' => $make('Plain Tale', genres: ['isekai']),
        'adult' => $make('Adult Tale', ['age_rating' => 'adult'], ['isekai']),
        'erotic' => $make('Erotic Tale', genres: ['erotica']),
        'dark' => $make('Dark Tale', genres: ['dark-romance', 'villain-romance']),
        'mature rated' => $make('Mature Tale', ['age_rating' => 'mature'], ['isekai']),
    ];
}

/**
 * The titles a reader gets from the text search, sorted.
 *
 * @return list<string>
 */
function foundBy(?User $viewer, array $filters = []): array
{
    return app(HybridSearch::class)->search('tale', SearchFilters::fromArray($filters), $viewer)->pluck('title')->sort()->values()->all();
}

/**
 * The titles a reader gets from a browse (filters only), sorted.
 *
 * @return list<string>
 */
function browsedBy(?User $viewer, array $filters = []): array
{
    return app(HybridSearch::class)->browse(SearchFilters::fromArray($filters), 50, $viewer)->pluck('title')->sort()->values()->all();
}

const VISIBLE_TO_EVERYONE = ['Dark Tale', 'Mature Tale', 'Plain Tale'];

test('a guest and a reader who has not opted in never get an adult-rated work or a mature genre', function (string $who) {
    gatedCatalog();
    $viewer = match ($who) {
        'guest' => null,
        'opted out' => User::factory()->create(),
        'switch without confirmation' => tap(User::factory()->create(), fn (User $user) => $user->profile()->updateOrCreate([], ['show_mature' => true])),
    };

    expect(foundBy($viewer))->toBe(VISIBLE_TO_EVERYONE)
        ->and(browsedBy($viewer))->toBe(VISIBLE_TO_EVERYONE);
})->with(['guest', 'opted out', 'switch without confirmation']);

test('a reader who opted in and confirmed they are 18 or older gets them too', function () {
    gatedCatalog();
    $viewer = User::factory()->showsMature()->create();

    expect(foundBy($viewer))->toBe(['Adult Tale', 'Dark Tale', 'Erotic Tale', 'Mature Tale', 'Plain Tale'])
        ->and(browsedBy($viewer))->toBe(['Adult Tale', 'Dark Tale', 'Erotic Tale', 'Mature Tale', 'Plain Tale']);
});

test('no filter value lifts the gate: asking for the adult rating or a mature genre finds nothing', function () {
    gatedCatalog();
    $viewer = User::factory()->create();

    expect(foundBy($viewer, ['ratings' => ['adult']]))->toBe([])
        ->and(foundBy($viewer, ['genres' => ['erotica']]))->toBe([])
        ->and(foundBy($viewer, ['genres' => ['mature-erotic-and-controversial-categories']]))->toBe(['Dark Tale']) // only what is visible anyway: Dark Tale is listed under it through a secondary parent
        ->and(browsedBy($viewer, ['ratings' => ['adult', 'mature']]))->toBe(['Mature Tale'])
        ->and(browsedBy($viewer, ['genres' => ['isekai', 'erotica']]))->toBe(['Mature Tale', 'Plain Tale']);
});

test('the gate is not an input: the filters never read it and never write it', function () {
    $filters = SearchFilters::fromArray(['matureAccess' => true, 'genres' => ['isekai']]);

    expect($filters->matureAccess)->toBeFalse()
        ->and($filters->toArray())->toBe(['genres' => ['isekai']])
        ->and((new SearchFilters(matureAccess: true))->isEmpty())->toBeTrue()
        ->and($filters->forViewer(User::factory()->create())->matureAccess)->toBeFalse()
        ->and($filters->forViewer(User::factory()->showsMature()->create())->matureAccess)->toBeTrue()
        ->and($filters->forViewer(null)->matureAccess)->toBeFalse();
});

test('a genre listed first elsewhere stays visible, and a mature genre does not', function () {
    $catalog = gatedCatalog();

    expect(browsedBy(null, ['genres' => ['romance']]))->toBe(['Dark Tale'])
        ->and(browsedBy(null, ['genres' => ['dark-romance']]))->toBe(['Dark Tale'])
        ->and(browsedBy(null, ['genres' => ['mature-erotic-and-controversial-categories']]))->toBe(['Dark Tale'])
        ->and(Genre::visible()->where('slug', 'dark-romance')->exists())->toBeTrue()
        ->and($catalog['erotic']->isRestricted())->toBeTrue()
        ->and($catalog['dark']->isRestricted())->toBeFalse()
        ->and($catalog['adult']->isRestricted())->toBeTrue()
        ->and($catalog['mature rated']->isRestricted())->toBeFalse();
});

test('the search page keeps them out even with a crafted url', function () {
    gatedCatalog();
    $this->actingAs(User::factory()->create());

    foreach ([
        ['q' => 'tale'],
        ['q' => 'tale', 'filters' => ['ratings' => ['adult'], 'genres' => ['erotica']]],
        ['filters' => ['ratings' => ['adult']]],
        ['filters' => ['genres' => ['mature-erotic-and-controversial-categories']]],
        ['q' => 'tale', 'filters' => ['matureAccess' => true, 'mature' => true]],
        ['q' => 'tale', 'matureAccess' => 1, 'mature' => 1, 'show_mature' => 1],
    ] as $query) {
        $this->get(route('search', $query))->assertOk()->assertDontSee('Adult Tale')->assertDontSee('Erotic Tale');
    }
});

test('the search page shows them to an opted-in adult', function () {
    gatedCatalog();
    $this->actingAs(User::factory()->showsMature()->create());

    $this->get(route('search', ['q' => 'tale']))->assertOk()->assertSee('Adult Tale')->assertSee('Erotic Tale');
});

test('discover keeps them out of its results, counts and genre list, and shows them to an opted-in adult', function () {
    $catalog = gatedCatalog();
    $mature = Genre::where('slug', 'mature-erotic-and-controversial-categories')->value('id');
    $romance = Genre::where('slug', 'romance')->value('id');

    $this->actingAs($gated = User::factory()->create());
    $page = $this->get(route('discover', ['genre' => 'erotica']))->assertOk();
    $page->assertDontSee('Erotic Tale')->assertDontSee('Adult Tale');

    expect(Genre::familyCounts())->not->toHaveKey($mature)
        ->and(Genre::familyCounts()[$romance])->toBe(1)
        ->and(Genre::familyCounts(true))->toHaveKey($mature)
        ->and(Genre::tagCounts()[Genre::where('slug', 'isekai')->value('id')])->toBe(2)
        ->and(Genre::tagCounts(true)[Genre::where('slug', 'isekai')->value('id')])->toBe(3);

    $this->get(route('discover'))->assertOk()->assertSee('3 stories');

    $this->actingAs(User::factory()->showsMature()->create());
    $this->get(route('discover', ['genre' => 'erotica']))->assertOk()->assertSee('Erotic Tale');
    $this->get(route('discover'))->assertOk()->assertSee('5 stories');
});

test('the facets offer a gated reader no mature family, subgenre or adult rating, and counts that match what they can reach', function () {
    gatedCatalog();
    $facets = app(TaxonomyFacets::class);
    $slugs = fn (array $tree) => collect($tree)->flatMap(fn (array $family) => [$family['slug'], ...collect($family['groups'])->flatMap(fn (array $group) => collect($group['items'])->pluck('slug'))->all()]);

    $gated = $facets->genres();
    $open = $facets->genres(true);

    expect($slugs($gated))->not->toContain('mature-erotic-and-controversial-categories', 'erotica', 'forbidden-romance')
        ->and($slugs($gated))->toContain('dark-romance', 'betrayal')
        ->and($slugs($open))->toContain('mature-erotic-and-controversial-categories', 'erotica', 'dark-romance')
        ->and(collect($gated)->firstWhere('slug', 'fantasy')['count'])->toBe(2)
        ->and(collect($open)->firstWhere('slug', 'fantasy')['count'])->toBe(3)
        ->and(collect($open)->firstWhere('slug', 'mature-erotic-and-controversial-categories')['count'])->toBe(2) // the erotica work and Dark Tale, which the family lists through a secondary parent
        ->and(array_keys($facets->ratings()))->not->toContain('adult')
        ->and(array_keys($facets->ratings(true)))->toContain('adult')
        ->and(array_keys($facets->labels()['genres']))->not->toContain('erotica')
        ->and(array_keys($facets->labels(true)['genres']))->toContain('erotica')
        ->and(collect($facets->panel()['ratings'])->pluck('value')->all())->not->toContain('adult');
});

test('home shelves and the hero skip them, with a separate cached pick for each access level', function () {
    gatedCatalog();
    Media::query()->update(['cover_url' => '/favicon.svg', 'ratings_count' => 5]);
    $shelves = app(HomeShelves::class);
    $titles = fn (?User $viewer): array => $shelves->shelf('trending', viewer: $viewer)->items->pluck('title')->sort()->values()->all();
    $hero = fn (?User $viewer): array => $shelves->hero(viewer: $viewer)->pluck('title')->sort()->values()->all();
    $open = User::factory()->showsMature()->create();

    expect($titles(null))->toBe(VISIBLE_TO_EVERYONE)
        ->and($titles($open))->toBe(['Adult Tale', 'Dark Tale', 'Erotic Tale', 'Mature Tale', 'Plain Tale'])
        ->and($titles(null))->toBe(VISIBLE_TO_EVERYONE)
        ->and($hero(null))->toBe(VISIBLE_TO_EVERYONE)
        ->and($hero($open))->toHaveCount(5)
        ->and(Cache::has('home:shelves:v2:gated:20'))->toBeTrue()
        ->and(Cache::has('home:shelves:v2:mature:20'))->toBeTrue();
});

test('similar stories skip them for a gated reader', function () {
    $catalog = gatedCatalog();
    $similar = fn (?User $viewer): array => app(SimilarMedia::class)->for($catalog['plain'], viewer: $viewer)->pluck('title')->sort()->values()->all();

    expect($similar(null))->toBe(['Mature Tale'])
        ->and($similar(User::factory()->showsMature()->create()))->toBe(['Adult Tale', 'Mature Tale']);
});

test('a direct link to a gated story answers with a page that says so and shows nothing of the title', function () {
    $catalog = gatedCatalog();
    $this->actingAs(User::factory()->create());

    foreach (['adult', 'erotic'] as $key) {
        $response = $this->get(route('stories.show', $catalog[$key]->slug))->assertForbidden();

        $response->assertSee('This title is for adult readers')->assertSee(route('privacy.edit'), false)
            ->assertDontSee($catalog[$key]->title)->assertDontSee($catalog[$key]->description ?? 'no description');
    }

    $this->get(route('stories.show', $catalog['dark']->slug))->assertOk();
    $this->get(route('stories.show', $catalog['mature rated']->slug))->assertOk();
});

test('an opted-in adult can open a gated story', function () {
    $catalog = gatedCatalog();
    $this->actingAs(User::factory()->showsMature()->create());

    $this->get(route('stories.show', $catalog['adult']->slug))->assertOk()->assertSee('Adult Tale');
    $this->get(route('stories.show', $catalog['erotic']->slug))->assertOk()->assertSee('Erotic Tale');
});

test('a story page lists no gated related story, series neighbour or volume', function () {
    $catalog = gatedCatalog();
    $series = Series::factory()->create();
    $series->media()->attach($catalog['plain'], ['position' => 1]);
    $series->media()->attach($catalog['adult'], ['position' => 2]);
    $series->media()->attach($catalog['dark'], ['position' => 3]);
    $catalog['plain']->relations()->attach($catalog['erotic'], ['relation' => 'sequel']);
    $catalog['plain']->relations()->attach($catalog['dark'], ['relation' => 'spin_off']);

    $this->actingAs(User::factory()->create());
    $html = $this->get(route('stories.show', $catalog['plain']->slug))->assertOk()->getContent();

    expect($html)->not->toContain('Adult Tale')->not->toContain('Erotic Tale')->toContain('Dark Tale');

    $this->actingAs(User::factory()->showsMature()->create());
    $this->get(route('stories.show', $catalog['plain']->slug))->assertOk()->assertSee('Adult Tale')->assertSee('Erotic Tale');
});

test('a series page lists no gated volume, and a series of only gated volumes is a 404', function () {
    $catalog = gatedCatalog();
    $series = Series::factory()->create();
    $series->media()->attach([$catalog['plain']->id => ['position' => 1], $catalog['adult']->id => ['position' => 2]]);
    $onlyAdult = Series::factory()->create();
    $onlyAdult->media()->attach([$catalog['adult']->id => ['position' => 1], $catalog['erotic']->id => ['position' => 2]]);

    $this->actingAs(User::factory()->create());
    $this->get(route('series.show', $series->slug))->assertOk()->assertSee('Plain Tale')->assertDontSee('Adult Tale');
    $this->get(route('series.show', $onlyAdult->slug))->assertNotFound();

    $this->actingAs(User::factory()->showsMature()->create());
    $this->get(route('series.show', $series->slug))->assertOk()->assertSee('Adult Tale');
    $this->get(route('series.show', $onlyAdult->slug))->assertOk()->assertSee('Erotic Tale');
});

test('explore lists no gated story and counts only the stories a reader can reach', function () {
    $catalog = gatedCatalog();
    $this->seed(DeweySeeder::class);
    classified(new Collection(array_values($catalog)), '813');
    $counts = app(DeweyCounts::class);
    $open = User::factory()->showsMature()->create();

    expect($counts->forClass(dewey('813')))->toBe(3)
        ->and($counts->forClass(dewey('813'), $open))->toBe(5)
        ->and($counts->forClass(dewey('800'), User::factory()->create()))->toBe(3);

    $this->actingAs(User::factory()->create());
    $this->get(route('explore.show', '813'))->assertOk()->assertDontSee('Adult Tale')->assertDontSee('Erotic Tale')->assertSee('Dark Tale');
    $this->get(route('explore.index'))->assertOk()->assertDontSee('Adult Tale');

    $this->actingAs($open);
    $this->get(route('explore.show', '813'))->assertOk()->assertSee('Adult Tale')->assertSee('Erotic Tale');
});

test('the command palette search honours the gate', function () {
    gatedCatalog();

    $this->actingAs(User::factory()->create());
    Livewire::test('command-palette-results', ['query' => 'tale'])->assertDontSee('Adult Tale')->assertDontSee('Erotic Tale')->assertSee('Plain Tale');

    $this->actingAs(User::factory()->showsMature()->create());
    Livewire::test('command-palette-results', ['query' => 'tale'])->assertSee('Adult Tale')->assertSee('Erotic Tale');
});

test('the cover of a gated story is not served to a reader who has not opted in, and is never cached by a shared cache', function () {
    Storage::fake('local');
    Http::fake(['s4.anilist.co/*' => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 200, ['Content-Type' => 'image/png'])]);
    $catalog = gatedCatalog();
    Media::query()->update(['cover_url' => 'https://s4.anilist.co/file/cover.png']);

    $this->actingAs(User::factory()->create());
    $this->get(route('covers.show', $catalog['adult']))->assertNotFound();
    $this->get(route('covers.show', $catalog['erotic']))->assertNotFound();
    $this->get(route('covers.show', $catalog['plain']))->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=86400, public');

    $this->actingAs(User::factory()->showsMature()->create());
    $this->get(route('covers.show', $catalog['adult']))->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=86400, private');
});

test('the sign-in showcase is always the gated list', function () {
    $catalog = gatedCatalog();
    Media::query()->update(['cover_url' => '/favicon.svg', 'ratings_count' => 5]);
    $catalog['adult']->update(['ratings_count' => 500]);

    expect(collect(Media::showcaseCovers())->pluck('title')->all())->not->toContain('Adult Tale');
});

test('access follows the profile, and a guest is never granted it', function () {
    expect(MatureAccess::allows(null))->toBeFalse()
        ->and(MatureAccess::allows(User::factory()->create()))->toBeFalse()
        ->and(MatureAccess::allows(User::factory()->showsMature()->create()))->toBeTrue()
        ->and(MatureAccess::cacheKey(null))->toBe('gated')
        ->and(MatureAccess::cacheKey(User::factory()->showsMature()->create()))->toBe('mature');
});
