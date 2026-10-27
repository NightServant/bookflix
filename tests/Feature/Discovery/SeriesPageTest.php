<?php

use App\Models\Creator;
use App\Models\Media;
use App\Models\Series;
use App\Models\User;

/**
 * A series whose volumes are attached at the given positions (a position of null is an unnumbered one).
 *
 * @param  list<array{0: float|int|null, 1: string, 2?: array<string, mixed>}>  $volumes
 */
function seriesWith(array $volumes, array $attributes = []): Series
{
    $series = Series::factory()->create($attributes);

    foreach ($volumes as $volume) {
        [$position, $title, $extra] = $volume + [2 => []];
        $series->media()->attach(Media::factory()->create(['title' => $title, ...$extra]), ['position' => $position]);
    }

    return $series;
}

test('volumes are ordered 1, 1.5, 2', function () {
    $series = Series::factory()->create();
    foreach ([[2, 'B'], [1, 'A'], [1.5, 'AB']] as [$pos, $t]) {
        $series->media()->attach(Media::factory()->create(['title' => $t]), ['position' => $pos]);
    }
    $this->actingAs(User::factory()->create())->get(route('series.show', $series->slug))->assertSeeInOrder(['A', 'AB', 'B']);
});

test('items without a position come last and an unknown series is 404', function () {
    $series = Series::factory()->create();
    $series->media()->attach(Media::factory()->create(['title' => 'NoPos']), ['position' => null]);
    $series->media()->attach(Media::factory()->create(['title' => 'First']), ['position' => 1]);
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('series.show', $series->slug))->assertSeeInOrder(['First', 'NoPos']);
    $this->actingAs($user)->get('/series/missing')->assertNotFound();
});

test('guests are sent to log in', function () {
    $this->get(route('series.show', Series::factory()->create()->slug))->assertRedirect(route('login'));
});

test('volumes carry bracketed index numbers in reading order', function () {
    $series = seriesWith([[2, 'Vol Two'], [1, 'Vol One'], [1.5, 'Vol Half']]);

    $html = $this->actingAs(User::factory()->create())->get(route('series.show', $series->slug))->getContent();

    expect(array_map(fn ($node) => trim($node->textContent), iterator_to_array(htmlQuery($html, '//*[@data-index]'))))->toBe(['[01]', '[01.5]', '[02]']);
});

test('the header names the series, counts its volumes, gives the year span and credits the creators once', function () {
    $series = seriesWith([[1, 'Vol One', ['publication_year' => 1994]], [2, 'Vol Two', ['publication_year' => 2001]]], ['title' => 'Monster Chronicles']);
    $urasawa = Creator::factory()->create(['name' => 'Naoki Urasawa']);
    $series->media->each(fn (Media $volume) => $volume->creators()->attach($urasawa, ['role' => 'author', 'position' => 0]));

    $html = $this->actingAs(User::factory()->create())->get(route('series.show', $series->slug))->getContent();

    expect(htmlQuery($html, '//h1')->length)->toBe(1)
        ->and(trim(htmlQuery($html, '//h1')->item(0)->textContent))->toBe('Monster Chronicles')
        ->and($html)->toContain('2 volumes')->toContain('1994 – 2001')
        ->and(substr_count($html, 'Naoki Urasawa'))->toBe(1);
});

test('the one primary call to action starts with volume 1', function () {
    $series = seriesWith([[2, 'Vol Two'], [1, 'Vol One']]);
    $first = $series->orderedMedia()->first();

    $html = $this->actingAs(User::factory()->create())->get(route('series.show', $series->slug))->getContent();

    expect(htmlQuery($html, '//main//a[@data-flux-button][contains(@class,"bg-[var(--color-accent)]")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//a[@href="'.route('stories.show', $first->slug).'"][normalize-space()="See volume 1"]')->length)->toBe(1);
});

test('the series status goes through the translator', function () {
    app('translator')->setLoaded(['*' => ['*' => ['en' => ['Ongoing' => 'En cours']]]]);
    $series = seriesWith([[1, 'Vol One']], ['status' => 'ongoing']);

    $this->actingAs(User::factory()->create())->get(route('series.show', $series->slug))->assertOk()->assertSee('En cours');
});

test('the header cover is the first volume and the only eager image, a missing cover falls back to its title', function () {
    $series = seriesWith([[1, 'Vol One', ['cover_url' => 'https://covers.test/one.jpg']], [2, 'Vol Two', ['cover_url' => null]]]);
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get(route('series.show', $series->slug))->getContent();

    expect(htmlQuery($html, '//header//img[@src="https://covers.test/one.jpg"][@fetchpriority="high"][@width][@height]')->length)->toBe(1)
        ->and(htmlQuery($html, '//li//*[@data-cover-fallback]')->length)->toBe(1);

    $coverless = seriesWith([[1, 'Vol One', ['cover_url' => null]]]);
    $html = $this->actingAs($user)->get(route('series.show', $coverless->slug))->getContent();

    expect(htmlQuery($html, '//header//*[@data-cover-fallback]')->length)->toBe(1)
        ->and(htmlQuery($html, '//header//img')->length)->toBe(0);
});

test('a series without volumes explains itself and offers the way on', function () {
    $series = Series::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('series.show', $series->slug))
        ->assertOk()
        ->assertSee('No volumes yet')
        ->assertSee(route('explore.index'))
        ->assertDontSee('See volume 1');
});

test('merged and deleted volumes stay off the page', function () {
    $series = seriesWith([[1, 'Kept Volume'], [2, 'Merged Volume'], [3, 'Deleted Volume']]);
    $series->media->firstWhere('title', 'Merged Volume')->update(['merged_into_id' => $series->media->firstWhere('title', 'Kept Volume')->id]);
    $series->media->firstWhere('title', 'Deleted Volume')->delete();

    $this->actingAs(User::factory()->create())->get(route('series.show', $series->slug))
        ->assertSee('Kept Volume')
        ->assertDontSee('Merged Volume')
        ->assertDontSee('Deleted Volume')
        ->assertSee('1 volume');
});

test('the series page runs a bounded number of queries without lazy loading', function () {
    $series = seriesWith(collect(range(1, 30))->map(fn (int $number) => [$number, "Volume {$number}"])->all());
    $this->actingAs(User::factory()->create());

    DB::enableQueryLog();
    $this->get(route('series.show', $series->slug))->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(12);
});

test('the story page links its series to this page', function () {
    $series = seriesWith([[1, 'Vol One']]);
    $volume = $series->media->first();

    $this->actingAs(User::factory()->create())->get(route('stories.show', $volume->slug))
        ->assertSee(route('series.show', $series->slug));
});

test('the header is a full-width band and the volumes sit in the page gutter', function () {
    $series = seriesWith([[1, 'Vol One']]);

    $html = $this->actingAs(User::factory()->create())->get(route('series.show', $series->slug))->getContent();

    expect(htmlQuery($html, '//main/div/header[not(contains(@class,"rounded-card"))]')->length)->toBe(1)
        ->and(htmlQuery($html, '//main/div[contains(@class,"px-gutter")]')->length)->toBe(0)
        ->and(htmlQuery($html, '//main/div/div[contains(@class,"px-gutter")]//h2[normalize-space()="Volumes"]')->length)->toBe(1);
});

test('volumes are paginated 100 to a page and the header counts them all', function () {
    $series = seriesWith(collect(range(1, 120))->map(fn (int $number) => [$number, "Volume {$number}"])->all());
    $user = User::factory()->create();

    $first = $this->actingAs($user)->get(route('series.show', $series->slug))->assertOk()->assertSee('120 volumes')->getContent();
    $second = $this->actingAs($user)->get(route('series.show', ['slug' => $series->slug, 'page' => 2]))->assertOk()->assertSee('120 volumes')->getContent();

    expect(htmlQuery($first, '//*[@data-index]')->length)->toBe(100)
        ->and($first)->toContain('Volume 100')->not->toContain('Volume 101')
        ->and(htmlQuery($second, '//*[@data-index]')->length)->toBe(20)
        ->and($second)->toContain('Volume 101')->toContain('Volume 120')->not->toContain('Volume 100<');
});

test('a series of 120 volumes still runs a bounded number of queries', function () {
    $series = seriesWith(collect(range(1, 120))->map(fn (int $number) => [$number, "Volume {$number}"])->all());
    $this->actingAs(User::factory()->create());

    DB::enableQueryLog();
    $this->get(route('series.show', $series->slug))->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(12);
});

test('the header stacks the first four volumes as books, the first one the LCP cover', function () {
    $series = seriesWith(collect(range(1, 6))->map(fn (int $number) => [$number, "Volume {$number}", ['cover_url' => "https://covers.test/{$number}.jpg"]])->all());

    $html = $this->actingAs(User::factory()->create())->get(route('series.show', $series->slug))->getContent();

    expect(htmlQuery($html, '//header//*[@data-series-stack]//*[@data-book-3d]')->length)->toBe(4)
        ->and(htmlQuery($html, '//header//*[@data-series-stack]//*[@data-scene-cover]')->length)->toBe(1)
        ->and(htmlQuery($html, '//header//*[@data-series-stack]//img[@fetchpriority="high"]')->length)->toBe(1);
});

test('a long description and the credits with their roles sit in "About This Series", a short blurb stays in the header', function () {
    $long = seriesWith([[1, 'Vol One', ['publication_year' => 1994]]], ['description' => str_repeat('A long tale of many words. ', 12), 'status' => 'completed']);
    $short = seriesWith([[1, 'Vol One']], ['description' => 'A short blurb.']);
    $long->media->first()->creators()->attach(Creator::factory()->create(['name' => 'Naoki Urasawa']), ['role' => 'author', 'position' => 0]);
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get(route('series.show', $long->slug))->getContent();
    $about = '//aside[.//h2[normalize-space()="About This Series"]]';

    expect(htmlQuery($html, $about.'//p[contains(., "A long tale")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//header//*[contains(., "A long tale")]')->length)->toBe(0)
        ->and(htmlQuery($html, $about.'//li[contains(., "Naoki Urasawa")][contains(., "Author")]')->length)->toBe(1)
        ->and(htmlQuery($html, $about.'//dd[normalize-space()="Completed"]')->length)->toBe(1)
        ->and(htmlQuery($html, $about.'//dd[normalize-space()="1994"]')->length)->toBe(1);

    $this->actingAs($user)->get(route('series.show', $short->slug))->assertSee('A short blurb.');
    expect(htmlQuery($this->actingAs($user)->get(route('series.show', $short->slug))->getContent(), '//header//p[contains(., "A short blurb.")]')->length)->toBe(1);
});
