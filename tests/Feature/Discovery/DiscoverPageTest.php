<?php

use App\Models\Genre;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
    $tiles = collect(htmlQuery($html, '//a[@data-format-tile]/span[1]'))->map(fn ($tile) => trim(preg_replace('/\s+/', ' ', $tile->textContent)));

    expect($tiles->all())->toBe(['Manga 3 stories', 'Novel 1 story']);
});

test('a format with no covers shows fallback covers instead of a hole, up to three', function () {
    Media::factory()->manga()->count(3)->create(['cover_url' => null]);
    Media::factory()->novel()->create(['cover_url' => null]);

    $html = $this->actingAs(User::factory()->create())->get(route('discover'))->getContent();
    $tiles = htmlQuery($html, '//a[@data-format-tile]');

    expect($tiles->length)->toBe(2)
        ->and(htmlQuery($html, '//a[@data-format-tile][contains(.,"Manga")]//*[@data-cover-fallback]')->length)->toBe(3)
        ->and(htmlQuery($html, '//a[@data-format-tile][contains(.,"Novel")]//*[@data-cover-fallback]')->length)->toBe(1);
});

test('the header is a full-bleed band holding the h1 and a collage of the formats', function () {
    Media::factory()->manga()->create(['cover_url' => 'https://example.test/a.jpg']);
    Media::factory()->novel()->create(['cover_url' => 'https://example.test/b.jpg']);

    $html = $this->actingAs(User::factory()->create())->get(route('discover'))->getContent();

    expect(htmlQuery($html, '//main/div/header[contains(@class,"bg-surface-deep")]//h1')->length)->toBe(1)
        ->and(htmlQuery($html, '//header//*[@data-test="collage"]//*[@data-book-3d]')->length)->toBeGreaterThan(0);
});

test('the tiles link to the filtered view and the active one is marked and clears itself', function () {
    Media::factory()->manga()->create();
    Media::factory()->novel()->create();
    $this->actingAs(User::factory()->create());

    $html = $this->get(route('discover'))->getContent();
    expect(htmlQuery($html, '//a[@data-format-tile][@href="'.route('discover', ['filters' => ['mediaTypes' => ['manga']]]).'#results"]')->length)->toBe(1);

    // An old link to ?type=manga still narrows to manga, and the tile that is on clears itself.
    $html = $this->get(route('discover', ['type' => 'manga']))->getContent();
    expect(htmlQuery($html, '//a[@data-format-tile][@aria-current="true"][contains(.,"Manga")][@href="'.route('discover').'"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//a[@data-format-tile][@aria-current="true"]')->length)->toBe(1);
});

test('the top genres show their counts and link to the filtered view', function () {
    $action = Genre::factory()->create(['name' => 'Action', 'slug' => 'action']);
    Genre::factory()->create(['name' => 'Empty Genre', 'slug' => 'empty-genre']);
    Media::factory()->manga()->count(2)->create()->each(fn (Media $media) => $media->genres()->attach($action));

    $html = $this->actingAs(User::factory()->create())->get(route('discover'))->getContent();

    expect(htmlQuery($html, '//a[@data-genre-chip][@href="'.route('discover', ['filters' => ['genres' => ['action']]]).'#results"]')->length)->toBe(1)
        ->and(trim(preg_replace('/\s+/', ' ', htmlQuery($html, '//a[@data-genre-chip]')->item(0)->textContent)))->toBe('Action 2')
        ->and(htmlQuery($html, '//a[@data-genre-chip]')->length)->toBe(1); // a genre with no story is no chip (the filter panel's data island does list it)
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

    expect($html)->not->toContain('Continue reading')
        ->not->toContain('Filter the catalog')->not->toContain('Browse by subject')
        ->and(collect(htmlQuery($html, '//nav[@aria-label="Quick links"]//a'))->map(fn (DOMElement $link): string => trim($link->textContent))->all())
        ->toBe(['My library', 'My collections', 'My history']);
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

    expect(preg_replace('/\s+/', ' ', htmlQuery($html, '//p[@data-test="catalog-stats"]')->item(0)->textContent))->toContain('and 13 genres')
        ->and(htmlQuery($html, '//a[@data-genre-chip]')->length)->toBe(12);
});

test('a second discover render reads the counts from the cache and stays within six queries', function () {
    $this->seed(DeweySeeder::class);
    Genre::factory()->count(3)->create()->each(fn (Genre $genre) => Media::factory()->manga()->create(['cover_url' => '/favicon.svg'])->genres()->attach($genre));
    $this->actingAs(User::factory()->create());

    $first = $this->get(route('discover'))->assertOk()->getContent();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $second = $this->get(route('discover'))->assertOk()->getContent();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(6)
        ->and(Cache::get('discover:type-counts:v2:gated'))->toBeArray()->each->toBeInt()
        ->and(Cache::get('discover:genre-counts:v3:gated'))->toHaveCount(3)->each->toBeInt()
        ->and(htmlQuery($second, '//a[@data-genre-chip]')->length)->toBe(htmlQuery($first, '//a[@data-genre-chip]')->length);
});

test('top genres are the families, counting the stories of their subgenres once', function () {
    $this->seed(TaxonomySeeder::class);
    $fantasy = Genre::where('slug', 'fantasy')->first();
    $both = Media::factory()->manga()->create(['title' => 'Both']);
    $both->genres()->attach([Genre::where('slug', 'isekai')->first()->id, Genre::where('slug', 'litrpg')->first()->id]);
    Media::factory()->manga()->create(['title' => 'Family Only'])->genres()->attach($fantasy);
    Media::factory()->manga()->create(['title' => 'Deleted'])->genres()->attach($fantasy);
    Media::where('title', 'Deleted')->first()->delete();
    Media::factory()->manga()->create(['title' => 'Courtroom'])->genres()->attach(Genre::where('slug', 'courtroom-drama')->first());
    Media::factory()->manga()->create(['title' => 'Erotica'])->genres()->attach(Genre::where('slug', 'erotica')->first());
    $this->actingAs(User::factory()->create());

    $counts = Livewire::test('pages::discover.index')->get('genreCounts');

    expect(Genre::familyCounts(true))->toHaveKey(Genre::where('slug', 'mature-erotic-and-controversial-categories')->value('id'));

    expect($counts)->toBe([$fantasy->id => 2, Genre::where('slug', 'mystery-crime-and-thriller')->value('id') => 1, Genre::where('slug', 'drama-and-human-relationships')->value('id') => 1])
        ->and(collect(Genre::query()->whereIn('id', array_keys($counts))->pluck('parent_id'))->filter()->all())->toBe([]);
});

test('a genre family filter lists the stories of its subgenres, and an old genre link finds the genre that replaced it', function () {
    $this->seed(TaxonomySeeder::class);
    Media::factory()->manga()->create(['title' => 'Isekai Hero'])->genres()->attach(Genre::where('slug', 'isekai')->first());
    Media::factory()->manga()->create(['title' => 'Courtroom'])->genres()->attach(Genre::where('slug', 'courtroom-drama')->first());
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::discover.index')->set('genre', 'fantasy')->assertSee('Isekai Hero')->assertDontSee('Courtroom');
    Livewire::test('pages::discover.index')->set('genre', 'drama-and-human-relationships')->assertSee('Courtroom')->assertDontSee('Isekai Hero');
    Livewire::test('pages::discover.index')->set('genre', 'mystery-crime-and-thriller')->assertSee('Courtroom');
    Livewire::test('pages::discover.index')->set('genre', 'drama')->assertSee('Courtroom')->assertDontSee('Isekai Hero');
});

test('the library quick link opens the library', function () {
    $html = $this->actingAs(User::factory()->create())->get(route('discover'))->getContent();

    expect(htmlQuery($html, '//nav[@aria-label="Quick links"]//a[@href="'.route('library.index').'"][normalize-space()="My library"]')->length)->toBe(1);
});
