<?php

use App\Discovery\Search\HybridSearch;
use App\Models\Genre;
use App\Models\Media;
use App\Models\SearchQuery;
use App\Models\User;
use Database\Seeders\MediaTypeSeeder;
use Livewire\Livewire;

test('searching shows results and logs one query row', function () {
    Media::factory()->create(['title' => 'Berserk', 'search_text' => 'berserk']);
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::search.index')->set('q', 'berserk')->assertSee('Berserk');

    $row = SearchQuery::sole();
    expect($row->query)->toBe('berserk')->and($row->results_count)->toBe(1)->and($row->user_id)->toBe($user->id)->and($row->session_hash)->toHaveLength(64);
});

test('the logged session hash is keyed, never the session id', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::search.index')->set('q', 'berserk');

    expect(SearchQuery::sole()->session_hash)->toBe(hash_hmac('sha256', session()->getId(), config('app.key')));
});

test('the normalized query is lowercased, accent stripped and single spaced', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::search.index')->set('q', '  Pokémon   GO ');

    expect(SearchQuery::sole()->normalized)->toBe('pokemon go');
});

test('a refinement links to the previous query', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::search.index')->set('q', 'berserk')->set('q', 'berserk manga');
    [$first, $second] = SearchQuery::orderBy('id')->get()->all();
    expect($first->previous_query_id)->toBeNull()->and($second->previous_query_id)->toBe($first->id);
});

test('changing a filter refines the search and records the filters', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::search.index')->set('q', 'berserk')->set('filters.mediaTypes', ['manga']);
    [$first, $second] = SearchQuery::orderBy('id')->get()->all();
    expect($second->previous_query_id)->toBe($first->id)->and($second->filters)->toBe(['mediaTypes' => ['manga']]);
});

test('clearing the search starts a fresh chain', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::search.index')->set('q', 'berserk')->set('q', '')->set('q', 'dune');
    [, $second] = SearchQuery::orderBy('id')->get()->all();
    expect($second->previous_query_id)->toBeNull();
});

test('zero results shows the empty state and is logged', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::search.index')->set('q', 'zzzxxyy')->assertSee('No stories found')->assertSee('Try');
    expect(SearchQuery::sole()->results_count)->toBe(0);
});

test('opted out users are logged anonymously', function () {
    $this->actingAs($user = User::factory()->create());
    $user->profile()->updateOrCreate([], ['personalization_enabled' => false]);
    Livewire::test('pages::search.index')->set('q', 'berserk');
    expect(SearchQuery::sole()->user_id)->toBeNull();
});

test('the same settled query is not logged twice', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::search.index')->set('q', 'berserk')->call('$refresh');
    expect(SearchQuery::count())->toBe(1);
});

test('turning the pages of a search does not log it again', function () {
    Media::factory()->count(30)->create(['search_text' => 'berserk']);
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::search.index')->set('q', 'berserk')->call('gotoPage', 2);
    expect(SearchQuery::count())->toBe(1);
});

test('queries shorter than two characters are not run or logged', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::search.index')->set('q', 'a')->assertDontSee('No stories found');
    Livewire::test('pages::search.index')->set('q', '   ');
    expect(SearchQuery::count())->toBe(0);
});

test('a search that arrives in the url is run and logged', function () {
    Media::factory()->create(['title' => 'Berserk', 'search_text' => 'berserk']);
    $this->actingAs(User::factory()->create())->get(route('search', ['q' => 'berserk']))->assertOk()->assertSee('Berserk');
    expect(SearchQuery::sole()->query)->toBe('berserk');
});

test('result links carry the search query id', function () {
    $m = Media::factory()->create(['title' => 'Berserk', 'search_text' => 'berserk']);
    $this->actingAs(User::factory()->create());
    $html = Livewire::test('pages::search.index')->set('q', 'berserk')->html();
    expect($html)->toContain(route('stories.show', $m->slug).'?sq='.SearchQuery::sole()->id);
});

test('results render without lazy loading in both layouts', function (string $view) {
    $manga = Media::factory()->manga()->create(['title' => 'Berserk', 'search_text' => 'berserk']);
    $manga->genres()->attach(Genre::factory()->create(['name' => 'Dark Fantasy']));
    $this->actingAs(User::factory()->create());

    Livewire::withQueryParams(['view' => $view])->test('pages::search.index')->set('q', 'berserk')
        ->assertSee('Berserk')
        ->assertSee($view === 'list' ? 'Dark Fantasy' : 'Berserk');
})->with(['grid', 'list']);

test('filters in the url restore the page state', function () {
    $this->actingAs(User::factory()->create());
    $this->get(route('search', ['q' => 'berserk', 'filters' => ['mediaTypes' => ['manga']]]))->assertOk();

    Livewire::withQueryParams(['q' => 'berserk', 'filters' => ['mediaTypes' => ['manga']]])->test('pages::search.index')
        ->assertSet('q', 'berserk')
        ->assertSet('filters.mediaTypes', ['manga']);
});

test('filters narrow the results', function () {
    Media::factory()->manga()->create(['title' => 'Berserk Manga', 'search_text' => 'berserk']);
    Media::factory()->novel()->create(['title' => 'Berserk Novel', 'search_text' => 'berserk']);
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::search.index')->set('q', 'berserk')->set('filters.mediaTypes', ['manga'])
        ->assertSee('Berserk Manga')->assertDontSee('Berserk Novel');
});

test('the view toggle switches grid and list and is reflected in the url', function () {
    Media::factory()->create(['title' => 'Berserk', 'description' => 'A blurb about the Black Swordsman.', 'search_text' => 'berserk']);
    $this->actingAs(User::factory()->create());

    $page = Livewire::test('pages::search.index')->set('q', 'berserk')
        ->assertSet('view', 'grid')->assertDontSee('A blurb about the Black Swordsman.')
        ->set('view', 'list')->assertSee('A blurb about the Black Swordsman.');

    expect($page->html())->toContain('data-view="list"');

    Livewire::withQueryParams(['q' => 'berserk', 'view' => 'list'])->test('pages::search.index')->assertSet('view', 'list')->assertSee('A blurb about the Black Swordsman.');
});

test('an unknown view falls back to the grid', function () {
    Media::factory()->create(['title' => 'Berserk', 'search_text' => 'berserk']);
    $this->actingAs(User::factory()->create());

    Livewire::withQueryParams(['q' => 'berserk', 'view' => 'carousel'])->test('pages::search.index')->assertSee('data-view="grid"', false);
});

test('active filters render as removable chips', function () {
    $this->seed(MediaTypeSeeder::class);
    $genre = Genre::factory()->create(['name' => 'Dark Fantasy', 'slug' => 'dark-fantasy']);
    $this->actingAs(User::factory()->create());

    $page = Livewire::test('pages::search.index')->set('q', 'berserk')
        ->set('filters.genres', [$genre->slug])
        ->set('filters.mediaTypes', ['manga'])
        ->assertSee('Dark Fantasy');

    expect($page->html())->toContain('Remove filter Dark Fantasy')->toContain('Remove filter Manga');

    $page->call('removeFilter', 'genres', 'dark-fantasy')
        ->assertSet('filters.genres', [])
        ->assertSet('filters.mediaTypes', ['manga'])
        ->assertDontSee('Remove filter Dark Fantasy');

    $page->call('clearFilters')->assertSet('filters.mediaTypes', [])->assertDontSee('Remove filter Manga');
});

test('removing a scalar filter chip clears it', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::search.index')->set('q', 'berserk')->set('filters.length', 'short')
        ->assertSee('Remove filter Short')
        ->call('removeFilter', 'length')
        ->assertSet('filters.length', '')
        ->assertDontSee('Remove filter Short');
});

test('filters without a text query browse the catalogue and are not logged', function () {
    Media::factory()->manga()->create(['title' => 'Berserk Manga']);
    Media::factory()->novel()->create(['title' => 'Plain Novel']);
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::search.index')->set('filters.mediaTypes', ['manga'])->assertSee('Berserk Manga')->assertDontSee('Plain Novel');

    expect(SearchQuery::count())->toBe(0);
});

test('the page opens with the search panel and one heading', function () {
    $this->actingAs(User::factory()->create());

    $html = $this->get(route('search'))->assertOk()->assertSee('What would you like to find?')->getContent();

    expect(substr_count($html, '<h1'))->toBe(1);
});

test('the header carries the command palette and the nav carries search', function () {
    $this->actingAs(User::factory()->create());

    $html = $this->get(route('home'))->assertOk()->getContent();
    $navs = primaryNavs($html);

    expect($html)->toContain('aria-haspopup="dialog"')
        ->and($navs['header'])->toContain(route('search'))
        ->and($navs['bottom'])->toContain(route('search'));
});

test('guests are redirected to login', function () {
    $this->get(route('search'))->assertRedirect(route('login'));
});

test('a pasted wall of text is capped before it is searched and logged', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::search.index')->set('q', str_repeat('ab ', 200))->assertOk();

    expect(mb_strlen(SearchQuery::sole()->query))->toBeLessThanOrEqual(HybridSearch::MAX_QUERY_LENGTH);
});

test('malformed UTF-8 in the URL renders instead of failing the log insert', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('search', ['q' => "\xFFab"]))->assertOk();

    expect(SearchQuery::count())->toBe(1);
});
