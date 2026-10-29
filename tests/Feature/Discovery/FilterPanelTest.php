<?php

use App\Models\ContentWarning;
use App\Models\Genre;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\MediaTypeSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Livewire;

/**
 * The data island of a rendered page, decoded.
 *
 * @return array<string, mixed>
 */
function filterIsland(string $html): array
{
    $island = htmlQuery($html, '//script[@id="filter-data"]');

    return $island->length ? json_decode($island->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR) : [];
}

/**
 * The text of the active filter chips, group by group: "Genre: Isekai, Romance | Hide: Gore".
 */
function chipSummary(string $html): string
{
    return collect(htmlQuery($html, '//ul[@aria-label="Active filters"]/li[ul]'))->map(function (DOMElement $group): string {
        $label = trim((string) (new DOMXPath($group->ownerDocument))->evaluate('string(span[1])', $group));
        $chips = collect((new DOMXPath($group->ownerDocument))->query('.//ul/li/span[1]', $group))->map(fn (DOMNode $chip): string => trim($chip->textContent))->implode(', ');

        return ($label === '' ? '' : $label.': ').$chips;
    })->implode(' | ');
}

beforeEach(function () {
    $this->seed([TaxonomySeeder::class, MediaTypeSeeder::class]);
});

test('active filters render as removable chips, grouped and labelled, and a content warning says what it does', function () {
    $this->actingAs(User::factory()->create());

    $html = Livewire::test('pages::search.index')
        ->set('filters.genres', ['isekai', 'romance'])
        ->set('filters.themes', ['war'])
        ->set('filters.tropes', ['chosen-one'])
        ->set('filters.demographics', ['seinen'])
        ->set('filters.ratings', ['teen'])
        ->set('filters.excludeWarnings', ['gore', 'violence'])
        ->set('filters.mediaTypes', ['manga'])
        ->html();

    expect(chipSummary($html))->toBe('Type: Manga | Genre: Isekai, Romance | Theme: War | Trope: Chosen One | Demographic: Seinen | Audience: Teen | Hide: Gore, Hide: Violence')
        ->and($html)->toContain('aria-label="Remove filter Hide: Gore"')->toContain('aria-label="Remove filter Isekai"')
        ->and(htmlQuery($html, '//ul[@aria-label="Active filters"]//button[@aria-label="Remove filter Isekai"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//ul[@aria-label="Hidden content warnings"]')->length)->toBe(1);
});

test('a chip removes its own value, Clear all removes every chip, and a content warning chip removes only that warning', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::search.index')
        ->set('filters.excludeWarnings', ['gore', 'violence'])
        ->set('filters.genres', ['isekai'])
        ->call('removeFilter', 'excludeWarnings', 'gore')
        ->assertSet('filters.excludeWarnings', ['violence'])
        ->assertSet('filters.genres', ['isekai'])
        ->assertSee('Hide: Violence')->assertDontSee('Hide: Gore')
        ->call('clearFilters')
        ->assertSet('filters.excludeWarnings', [])
        ->assertSet('filters.genres', [])
        ->assertDontSee('Active filters');
});

test('the genre, theme, trope, demographic, rating and warning filters narrow the results on their own', function () {
    $isekai = Media::factory()->manga()->create(['title' => 'Isekai Hero', 'age_rating' => 'teen'])->genres()->attach(Genre::where('slug', 'isekai')->first());
    Media::factory()->manga()->create(['title' => 'Plain Story']);
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::search.index')
        ->set('filters.genres', ['fantasy'])
        ->assertSee('Isekai Hero')->assertDontSee('Plain Story')
        ->set('filters.genres', [])
        ->set('filters.ratings', ['teen'])
        ->assertSee('Isekai Hero')->assertDontSee('Plain Story');
});

test('excluding a warning keeps works whose warning status is unknown', function () {
    $gore = ContentWarning::where('slug', 'gore')->firstOrFail();
    Media::factory()->manga()->create(['title' => 'Has Gore'])->warnings()->attach($gore, ['status' => 'present']);
    Media::factory()->manga()->create(['title' => 'Gore Unknown'])->warnings()->attach($gore, ['status' => 'unknown']);
    Media::factory()->manga()->create(['title' => 'Never Recorded']);
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::search.index')
        ->set('filters.excludeWarnings', ['gore'])
        ->assertDontSee('Has Gore')->assertSee('Gore Unknown')->assertSee('Never Recorded');
});

test('filters are kept in the url with a history entry per change, and a link restores all of them', function () {
    $this->actingAs(User::factory()->create());
    $filters = [
        'genres' => ['isekai', 'romance'],
        'themes' => ['war'],
        'tropes' => ['chosen-one'],
        'demographics' => ['seinen'],
        'ratings' => ['teen'],
        'excludeWarnings' => ['gore'],
        'mediaTypes' => ['manga'],
        'statuses' => ['completed'],
        'languages' => ['en'],
        'years' => [1990, 2010],
        'length' => 'short',
        'minRating' => '8',
    ];

    foreach (['pages::search.index', 'pages::discover.index'] as $page) {
        $property = (new ReflectionClass(Livewire::test($page)->instance()))->getProperty('filters');
        $url = $property->getAttributes(Url::class)[0]->newInstance();

        expect($url->history)->toBeTrue();

        $state = Livewire::withQueryParams(['filters' => $filters])->test($page)->get('filters');

        expect($state)->toEqual($filters);

        // what the page writes back is what a link carries
        expect(Livewire::withQueryParams(['filters' => $state])->test($page)->get('filters'))->toEqual($filters);
    }
});

test('only the choices that were made travel in the state, and a malformed link does not break the page', function () {
    $this->actingAs(User::factory()->create());

    $state = Livewire::withQueryParams(['filters' => ['genres' => 'isekai', 'themes' => ['a' => 'war', 'b' => ['x']], 'length' => '', 'minRating' => '', 'years' => 5, 'bogus' => ['x']]])
        ->test('pages::search.index')->assertOk()->get('filters');

    expect($state)->not->toHaveKey('length')->not->toHaveKey('minRating')->not->toHaveKey('bogus')
        ->and($state['genres'])->toBe(['isekai'])
        ->and($state['themes'])->toBe(['war'])
        ->and($state['years'])->toBe([5]);

    $this->get(route('search', ['filters' => 'junk']))->assertOk();
    $this->get(route('discover', ['filters' => ['genres' => [['x']]]]))->assertOk();
});

test('a picked family stays one value however many subgenres it holds, and the genre cap fits the whole tree', function () {
    $this->actingAs(User::factory()->create());
    $every = Genre::query()->pluck('slug')->all();

    $state = Livewire::test('pages::search.index')->set('filters.genres', $every)->get('filters');

    expect($state['genres'])->toHaveCount(count($every));
});

test('the page ships the taxonomy once as a json island and the panel markup once as a template', function () {
    $media = Media::factory()->manga()->create();
    $media->genres()->attach(Genre::where('slug', 'isekai')->first());
    $this->actingAs(User::factory()->create());

    foreach ([route('search'), route('discover')] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        $island = filterIsland($html);
        $fantasy = collect($island['genres'])->firstWhere('slug', 'fantasy');
        $isekai = collect($fantasy['groups'])->flatMap(fn (array $group) => $group['items'])->firstWhere('slug', 'isekai');

        expect(substr_count($html, 'id="filter-data"'))->toBe(1)
            ->and(substr_count($html, 'id="filter-panel-template"'))->toBe(1)
            ->and(substr_count($html, 'Heroic Fantasy'))->toBe(1)
            ->and($fantasy['count'])->toBe(1)
            ->and($isekai['count'])->toBe(1)
            ->and(array_keys($island))->toEqualCanonicalizing(['types', 'genres', 'themes', 'tropes', 'demographics', 'ratings', 'warnings', 'statuses', 'languages', 'lengths', 'minRatings'])
            ->and(collect($island['warnings'])->pluck('value')->all())->toContain('gore')
            ->and(collect($island['tropes'])->pluck('name')->all())->toBe(['Romance tropes', 'Fantasy and progression tropes', 'Mystery and psychological tropes'])
            ->and(htmlQuery($html, '//aside//*[@x-html]')->length)->toBe(1)
            ->and(htmlQuery($html, '//template[@id="filter-panel-template"]//*[@x-for="family in data.genres"]')->length)->toBe(1);
    }
});

test('an update does not send the island or the tree again', function () {
    Media::factory()->manga()->create();
    $this->actingAs(User::factory()->create());

    $update = Livewire::test('pages::search.index')->set('filters.genres', ['isekai'])->html();

    expect($update)->not->toContain('filter-data')->not->toContain('Heroic Fantasy')->not->toContain('filter-panel-template');
});

test('the island offers a gated reader no adult rating or mature family, and an opted-in adult both', function () {
    $this->actingAs(User::factory()->create());
    $gated = filterIsland($this->get(route('search'))->getContent());

    $this->actingAs(User::factory()->showsMature()->create());
    $open = filterIsland($this->get(route('search'))->getContent());

    expect(collect($gated['ratings'])->pluck('value')->all())->not->toContain('adult')
        ->and(collect($gated['genres'])->pluck('slug')->all())->not->toContain('mature-erotic-and-controversial-categories')
        ->and(collect($open['ratings'])->pluck('value')->all())->toContain('adult')
        ->and(collect($open['genres'])->pluck('slug')->all())->toContain('mature-erotic-and-controversial-categories');
});

test('a subgenre listed under two families is in the island under both', function () {
    $this->actingAs(User::factory()->create());
    $island = filterIsland($this->get(route('search'))->getContent());
    $under = fn (string $family): array => collect(collect($island['genres'])->firstWhere('slug', $family)['groups'])->flatMap(fn (array $group) => collect($group['items'])->pluck('slug'))->all();

    expect($under('mystery-crime-and-thriller'))->toContain('courtroom-drama')
        ->and($under('drama-and-human-relationships'))->toContain('courtroom-drama')
        ->and($under('fantasy'))->toContain('isekai')->not->toContain('courtroom-drama');
});

test('the warnings group says that unknown is not hidden, and the panel has the separate groups', function () {
    $this->actingAs(User::factory()->create());
    $template = htmlQuery($this->get(route('search'))->getContent(), '//template[@id="filter-panel-template"]')->item(0);
    $text = preg_replace('/\s+/', ' ', $template->textContent);
    $html = $template->ownerDocument->saveHTML($template);
    $sections = collect(htmlQuery($html, '//section/h3/button/span[1]'))->map(fn (DOMNode $node) => trim($node->textContent))->all();

    expect($text)->toContain('Hide works with:')->toContain('Works whose warning status is unknown are not hidden: unknown does not mean absent.')
        ->and($sections)->toBe(['Format', 'Genre', 'Themes', 'Tropes', 'Demographic', 'Audience rating', 'Content warnings', 'More filters']);
});

test('discover takes the old type and genre links into the filters, ignoring unknown ones, and shows a family as a chip', function () {
    Media::factory()->manga()->create(['title' => 'Manga One'])->genres()->attach(Genre::where('slug', 'isekai')->first());
    Media::factory()->novel()->create(['title' => 'Novel One']);
    $this->actingAs(User::factory()->create());

    Livewire::withQueryParams(['type' => 'manga', 'genre' => 'isekai'])->test('pages::discover.index')
        ->assertSet('filters.mediaTypes', ['manga'])->assertSet('filters.genres', ['isekai'])
        ->assertSet('type', null)->assertSet('genre', null)
        ->assertSee('Manga One')->assertDontSee('Novel One');

    Livewire::withQueryParams(['genre' => 'action', 'type' => 'no-such-type'])->test('pages::discover.index')
        ->assertSet('filters.genres', ['action-and-adventure'])->assertSet('filters.mediaTypes', []);

    $family = Livewire::withQueryParams(['family' => 'prose'])->test('pages::discover.index')->assertSee('Novel One')->assertDontSee('Manga One');

    expect(chipSummary($family->html()))->toBe('Family: Prose');

    $family->call('removeFilter', 'family')->assertSet('family', null);
});

test('discover applies the same filter groups as search, and clearing them clears the family too', function () {
    Media::factory()->manga()->create(['title' => 'Manga One'])->genres()->attach(Genre::where('slug', 'isekai')->first());
    Media::factory()->manga()->create(['title' => 'Manga Two']);
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::discover.index')
        ->set('family', 'sequential_art')
        ->set('filters.genres', ['fantasy'])
        ->assertSee('Manga One')->assertDontSee('Manga Two')
        ->call('clearFilters')
        ->assertSet('family', null)->assertSet('filters.genres', []);
});

test('the filters stay within the query budget when every group is used, and a second render reads the lists from the cache', function () {
    Media::factory()->manga()->count(15)->create(['cover_url' => '/favicon.svg', 'search_text' => 'dune'])->each(fn (Media $media) => $media->genres()->attach(Genre::where('slug', 'isekai')->first()));
    $this->actingAs(User::factory()->create());
    $query = ['q' => 'dune', 'filters' => ['genres' => ['fantasy', 'romance'], 'themes' => ['war'], 'tropes' => ['chosen-one'], 'demographics' => ['seinen'], 'ratings' => ['teen'], 'excludeWarnings' => ['gore', 'violence'], 'mediaTypes' => ['manga']]];
    $count = function () use ($query): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('search', $query))->assertOk();

        return count(DB::getQueryLog());
    };

    $first = $count();
    $second = $count();

    expect($first)->toBeLessThanOrEqual(22)->and($second)->toBeLessThanOrEqual($first - 4);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(route('discover', ['filters' => $query['filters']]))->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(30);
});
