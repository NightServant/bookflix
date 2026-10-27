<?php

use App\Models\Genre;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('discover filters by media family', function () {
    Media::factory()->manga()->create(['title' => 'Panel Story']);
    Media::factory()->novel()->create(['title' => 'Prose Story']);
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::discover.index')->set('family', 'sequential_art')->assertSee('Panel Story')->assertDontSee('Prose Story');
});

test('a genre filter and the subject entry are present', function () {
    $this->actingAs(User::factory()->create())->get(route('discover'))->assertOk()->assertSee('Explore by Subject')->assertSee(route('explore.index'));
});

test('every section heading is Title Case', function () {
    $media = Media::factory()->create();
    $media->genres()->attach(Genre::factory()->create());

    $this->actingAs(User::factory()->create())->get(route('discover'))->assertOk()
        ->assertSee('Browse by Format')->assertSee('Top Genres')->assertSee('Explore by Subject')
        ->assertDontSee('Browse by format')->assertDontSee('Top genres')->assertDontSee('Explore by subject');
});

test('an invalid family is ignored', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test('pages::discover.index')->set('family', 'bogus')->assertHasNoErrors()->assertOk();
});

test('an unknown type or genre is ignored and browses nothing', function () {
    Media::factory()->manga()->create(['title' => 'Panel Story']);
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::discover.index')->set('type', 'no-such-type')->set('genre', 'no-such-genre')->assertHasNoErrors()->assertDontSee('Panel Story');
});

test('a type filter lists only that type and a genre filter only that genre', function () {
    $action = Genre::factory()->create(['name' => 'Action', 'slug' => 'action']);
    Media::factory()->manga()->create(['title' => 'Manga One'])->genres()->attach($action);
    Media::factory()->manga()->create(['title' => 'Manga Two']);
    Media::factory()->novel()->create(['title' => 'Novel One'])->genres()->attach($action);
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::discover.index')->set('type', 'manga')->assertSee('Manga One')->assertSee('Manga Two')->assertDontSee('Novel One');
    Livewire::test('pages::discover.index')->set('genre', 'action')->assertSee('Manga One')->assertSee('Novel One')->assertDontSee('Manga Two');
    Livewire::test('pages::discover.index')->set('type', 'novel')->set('genre', 'action')->assertSee('Novel One')->assertDontSee('Manga One');
});

test('type tiles show counts', function () {
    Media::factory()->manga()->count(3)->create();
    Media::factory()->novel()->create();
    Media::factory()->manga()->create()->delete();

    $html = $this->actingAs(User::factory()->create())->get(route('discover'))->getContent();
    $tiles = collect(htmlQuery($html, '//a[@data-format-tile]'))->map(fn ($tile) => trim(preg_replace('/\s+/', ' ', $tile->textContent)));

    expect($tiles->all())->toBe(['Manga 3 stories', 'Novel 1 story']);
});

test('the tiles link to the filtered view and the active one is marked and clears itself', function () {
    Media::factory()->manga()->create();
    Media::factory()->novel()->create();
    $this->actingAs(User::factory()->create());

    $html = $this->get(route('discover'))->getContent();
    expect(htmlQuery($html, '//a[@data-format-tile][@href="'.route('discover', ['type' => 'manga']).'#results"]')->length)->toBe(1);

    $html = $this->get(route('discover', ['type' => 'manga']))->getContent();
    expect(htmlQuery($html, '//a[@data-format-tile][@aria-current="true"][contains(.,"Manga")][@href="'.route('discover').'"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//a[@data-format-tile][@aria-current="true"]')->length)->toBe(1);
});

test('the top genres show their counts and link to the filtered view', function () {
    $action = Genre::factory()->create(['name' => 'Action', 'slug' => 'action']);
    Genre::factory()->create(['name' => 'Empty Genre', 'slug' => 'empty-genre']);
    Media::factory()->manga()->count(2)->create()->each(fn (Media $media) => $media->genres()->attach($action));

    $html = $this->actingAs(User::factory()->create())->get(route('discover'))->getContent();

    expect(htmlQuery($html, '//a[@data-genre-chip][@href="'.route('discover', ['genre' => 'action']).'#results"]')->length)->toBe(1)
        ->and(trim(preg_replace('/\s+/', ' ', htmlQuery($html, '//a[@data-genre-chip]')->item(0)->textContent)))->toBe('Action 2')
        ->and($html)->not->toContain('Empty Genre');
});

test('quick links point at existing routes', function () {
    $html = $this->actingAs(User::factory()->create())->get(route('discover'))->getContent();
    $links = htmlQuery($html, '//nav[@aria-label="Quick links"]//a');

    expect($links->length)->toBeGreaterThan(0);

    foreach ($links as $link) {
        expect(fn () => app('router')->getRoutes()->match(Request::create($link->getAttribute('href'))))->not->toThrow(Throwable::class);
    }
});

test('quick links only list destinations that exist yet', function () {
    $html = $this->actingAs(User::factory()->create())->get(route('discover'))->getContent();

    expect($html)->toContain('Browse by subject')->toContain('Filter the catalog')
        ->and($html)->not->toContain('Continue reading')->not->toContain('My collections');
});

test('discover opens with the search panel and ends its subject row with the way into the library', function () {
    $this->seed(DeweySeeder::class);
    $media = Media::factory()->create();
    classified($media, '813');

    $html = $this->actingAs(User::factory()->create())->get(route('discover'))->getContent();

    expect(htmlQuery($html, '//main//h1')->length)->toBe(1)
        ->and(htmlQuery($html, '//main//search//label[normalize-space()="What would you like to find?"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//main//search//a[normalize-space()="Browse Discover"]')->length)->toBe(0)
        ->and(htmlQuery($html, '//main//a[@href="'.route('explore.show', '800').'"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//main//a[@href="'.route('explore.index').'"][contains(.,"Explore the Library")]')->length)->toBe(1);
});

test('the discover nav item opens this page and stays current on the explore pages', function () {
    $this->seed(DeweySeeder::class);
    $this->actingAs(User::factory()->create());
    $discover = preg_quote(route('discover'), '#');

    foreach ([route('discover'), route('explore.index'), route('explore.show', '813')] as $url) {
        $navs = primaryNavs($this->get($url)->getContent());

        expect($navs['header'])->toMatch('#<a href="'.$discover.'"[^>]*data-current="data-current"#')
            ->and($navs['bottom'])->toMatch('#<a\s+href="'.$discover.'"[^>]*aria-current="page"#');
    }
});

test('an empty catalog explains itself, and only admins are offered the sync', function () {
    $this->actingAs(User::factory()->create())->get(route('discover'))
        ->assertOk()
        ->assertSee('The catalog is empty')
        ->assertDontSee('Sync providers');

    $this->actingAs(User::factory()->admin()->create())->get(route('discover'))
        ->assertSee('Sync providers')
        ->assertSee(route('admin.providers'));
});

test('discover runs a bounded number of queries without lazy loading', function () {
    $this->seed(DeweySeeder::class);
    $genre = Genre::factory()->create();
    Media::factory()->manga()->count(15)->create(['cover_url' => '/favicon.svg'])->each(fn (Media $media) => $media->genres()->attach($genre));
    Media::factory()->novel()->count(15)->create();
    $this->actingAs(User::factory()->create());

    DB::enableQueryLog();
    $this->get(route('discover', ['type' => 'manga']))->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(30);
});

test('the intro counts every genre with stories, not just the top chips', function () {
    Genre::factory()->count(13)->create()->each(fn (Genre $genre) => Media::factory()->manga()->create()->genres()->attach($genre));
    Genre::factory()->create();

    $html = $this->actingAs(User::factory()->create())->get(route('discover'))->getContent();

    expect(preg_replace('/\s+/', ' ', htmlQuery($html, '//header/p')->item(0)->textContent))->toContain('and 13 genres')
        ->and(htmlQuery($html, '//a[@data-genre-chip]')->length)->toBe(12);
});
