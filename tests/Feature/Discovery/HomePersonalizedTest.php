<?php

use App\Models\Collection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * Home as a reader with history sees it and as a new one does (Phase 6 design: ten sign-off decisions). The shelves themselves are
 * ShelfBuilderTest; this is the page: order, the getting-started card, See all, reasons, "Not interested", Your Collections.
 */

/**
 * The keys of the shelves on the page, in order (the ids of the section headings, without their `shelf-` prefix).
 *
 * @return list<string>
 */
function pageShelves(string $html): array
{
    $keys = [];

    foreach (htmlQuery($html, '//main//section[starts-with(@aria-labelledby, "shelf-")]') as $section) {
        $keys[] = substr($section->getAttribute('aria-labelledby'), strlen('shelf-'));
    }

    return $keys;
}

function homeHtml(User $user): string
{
    return test()->actingAs($user)->get(route('home'))->assertOk()->getContent();
}

test('a reader with history gets the personal shelves in master plan order, staff picks after Your Collections, and no getting-started card', function () {
    [$user, $catalog] = richReader();
    collectionOf($user, [$catalog[0], $catalog[1]], ['name' => 'Weekend reads']);

    $html = homeHtml($user);
    $keys = pageShelves($html);

    expect(array_slice($keys, 0, 9))->toBe(['continue-reading', 'recommended', 'because-you-read', 'new-releases', 'similar-to', 'trending', 'popular-in-genres', 'discover-new', 'your-collections'])
        ->and(array_slice($keys, 9))->toHaveCount(2)->and($keys[9])->toStartWith('collection-')->and($keys[10])->toBe('explore-by-subject')
        ->and(htmlQuery($html, '//*[@data-getting-started]')->length)->toBe(0)
        ->and(htmlQuery($html, '//main//h2[@id="shelf-your-collections"]')->length)->toBe(1)
        ->and($html)->toContain('Weekend reads')->toContain('Picked by our editors');
});

test('trending is the only gradient shelf on the page', function () {
    [$user] = richReader();

    expect(substr_count(homeHtml($user), 'from-featured-from'))->toBe(1);
});

test('a new reader gets the getting-started card, the staff pick first and no personal shelves', function () {
    richReader();
    $user = User::factory()->create();

    $html = homeHtml($user);
    $keys = pageShelves($html);

    expect($keys)->not->toContain('recommended', 'because-you-read', 'similar-to')
        ->and($keys[0])->toStartWith('collection-')
        ->and(htmlQuery($html, '//*[@data-getting-started]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@data-getting-started]//a[@href="'.route('onboarding', ['step' => 3]).'"][normalize-space()="Choose stories"]')->length)->toBe(1)
        ->and(strpos($html, 'data-getting-started'))->toBeLessThan(strpos($html, 'shelf-new-releases'))
        ->and(strpos($html, '<search'))->toBeLessThan(strpos($html, 'data-getting-started'));
});

test('a reader who opted out has the cold layout without the card or any personal shelf', function () {
    [$user] = richReader();
    $user->profile->update(['personalization_enabled' => false]);

    $html = homeHtml($user->fresh());
    $keys = pageShelves($html);

    expect($keys)->not->toContain('recommended', 'because-you-read', 'similar-to', 'popular-in-genres', 'discover-new')
        ->and($keys)->toContain('new-releases', 'trending', 'explore-by-subject')
        ->and(htmlQuery($html, '//*[@data-getting-started]')->length)->toBe(0)
        ->and(htmlQuery($html, '//*[@data-tile-menu]')->length)->toBe(0);
});

test('a reader without mature access sees no gated story anywhere on Home', function () {
    [$user, $catalog] = richReader();
    $gated = matureStory('Hidden Erotic Tale');
    $gated->update(['ratings_count' => 5000, 'popularity_score' => 1, 'published_on' => now()->subDay()]);
    similar($catalog[0], $gated, 'lsa', 0.99, activeVersion('lsa'));
    $user->profile->update(['show_mature' => false]);

    expect(homeHtml($user->fresh()))->not->toContain('Hidden Erotic Tale');
});

test('See all appears only where a page stands behind the shelf', function () {
    [$user, $catalog] = richReader();
    $catalog[0]->genres()->firstOrFail();

    $html = homeHtml($user);
    $seeAll = fn (string $key): int => htmlQuery($html, '//section[@aria-labelledby="shelf-'.$key.'"]//a[contains(@aria-label,"See all in")]')->length;

    expect($seeAll('recommended'))->toBe(0)
        ->and($seeAll('similar-to'))->toBe(0)
        ->and($seeAll('discover-new'))->toBe(0)
        ->and($seeAll('because-you-read'))->toBe(1)
        ->and($seeAll('new-releases'))->toBe(1)
        ->and($seeAll('trending'))->toBe(1)
        ->and($seeAll('popular-in-genres'))->toBe(1)
        ->and($seeAll('explore-by-subject'))->toBe(1);

    $link = htmlQuery($html, '//section[@aria-labelledby="shelf-because-you-read"]//a[contains(@aria-label,"See all in")]')->item(0)->getAttribute('href');
    expect($link)->toStartWith(route('search'))->toContain(rawurlencode('filters[genres][0]'));
});

test('"Not interested" is on the five personal shelves only, and every recommended tile reports clicks', function () {
    [$user] = richReader();
    $html = homeHtml($user);
    $menus = fn (string $key): int => htmlQuery($html, '//section[@aria-labelledby="shelf-'.$key.'"]//button[@data-tile-menu]')->length;
    $tracked = fn (string $key): int => htmlQuery($html, '//section[@aria-labelledby="shelf-'.$key.'"]//a[@data-recommendation]')->length;

    foreach (['recommended', 'because-you-read', 'similar-to', 'popular-in-genres', 'discover-new'] as $key) {
        expect($menus($key))->toBeGreaterThan(0, $key);
    }

    foreach (['trending', 'new-releases'] as $key) {
        expect($menus($key))->toBe(0)->and($tracked($key))->toBeGreaterThan(0, $key);
    }

    expect(htmlQuery($html, '//main//a[contains(@href,"rec=")]')->length)->toBe(0)
        ->and(htmlQuery($html, '//section[starts-with(@aria-labelledby,"shelf-collection-")]//button[@data-tile-menu]')->length)->toBe(0);
});

test('Recommended and Popular in Your Genres tiles say why, with the title or genre in white Medium; Because You Read tiles do not repeat it', function () {
    [$user] = richReader();
    $html = homeHtml($user);

    expect(htmlQuery($html, '//section[@aria-labelledby="shelf-recommended"]//span[contains(@class,"line-clamp-3")]')->length)->toBeGreaterThan(0)
        ->and(htmlQuery($html, '//section[@aria-labelledby="shelf-popular-in-genres"]//span[contains(@class,"line-clamp-3")][contains(.,"Matches your interest in")]/span[contains(@class,"font-medium")]')->length)->toBeGreaterThan(0)
        ->and(htmlQuery($html, '//section[@aria-labelledby="shelf-because-you-read"]//span[contains(@class,"line-clamp-3")]')->length)->toBe(0)
        ->and(htmlQuery($html, '//section[@aria-labelledby="shelf-trending"]//span[contains(@class,"line-clamp-3")]')->length)->toBe(0);
});

test('Your Collections joins when the reader has one', function () {
    [$user, $catalog] = richReader();
    $count = fn () => htmlQuery(homeHtml($user), '//section[@aria-labelledby="shelf-your-collections"]//li')->length;

    expect($count())->toBe(0);

    collectionOf($user, [$catalog[1]], ['name' => 'Mine 1']);
    expect($count())->toBe(1);
});

test('the staff picks lead a new reader\'s page and stay after the collections for a returning one', function () {
    [$user] = richReader();
    $returning = pageShelves(homeHtml($user));

    expect(array_search('discover-new', $returning))->toBeLessThan(array_search('collection-'.Collection::where('is_editorial', true)->value('id'), $returning));
});

test('home with a warm cache runs at most 25 queries for a reader with history', function () {
    [$user] = richReader();
    $this->actingAs($user);

    DB::enableQueryLog();
    $this->get(route('home'))->assertOk();
    $cold = count(DB::getQueryLog());
    DB::flushQueryLog();
    $this->get(route('home'))->assertOk();
    $warm = count(DB::getQueryLog());

    fwrite(STDERR, "\nhome, reader with history: {$cold} cold, {$warm} warm\n");
    expect($warm)->toBeLessThanOrEqual(25);
});

test('the getting-started card opens the last onboarding step', function () {
    Livewire\Livewire::actingAs(User::factory()->create())->withQueryParams(['step' => 3])->test('pages::onboarding.index')->assertSet('step', 3)->assertSee('Which of these have you read?');
});

test('onboarding starts at the first step without a step, or with one that is not the last', function () {
    Livewire\Livewire::actingAs(User::factory()->create())->withQueryParams(['step' => 2])->test('pages::onboarding.index')->assertSet('step', 1);
});

test('Your Collections shows every collection, and a fourth card invites a new one when there would be three', function () {
    [$user, $catalog] = richReader();
    $cards = fn () => htmlQuery(homeHtml($user), '//section[@aria-labelledby="shelf-your-collections"]//li');
    $new = fn () => htmlQuery(homeHtml($user), '//section[@aria-labelledby="shelf-your-collections"]//a[@data-new-collection]');

    foreach (range(1, 2) as $n) {
        collectionOf($user, [$catalog[$n]], ['name' => "Mine {$n}"]);
    }
    expect($cards()->length)->toBe(2)->and($new()->length)->toBe(0);

    collectionOf($user, [$catalog[3]], ['name' => 'Mine 3']);
    $html = homeHtml($user);
    $link = htmlQuery($html, '//section[@aria-labelledby="shelf-your-collections"]//a[@data-new-collection]');

    expect(htmlQuery($html, '//section[@aria-labelledby="shelf-your-collections"]//li')->length)->toBe(4)
        ->and(htmlQuery($html, '//section[@aria-labelledby="shelf-your-collections"]//li[not(.//a[@data-new-collection])]')->length)->toBe(3)
        ->and($link->length)->toBe(1)
        ->and($link->item(0)->getAttribute('href'))->toBe(route('collections.index'))
        ->and($link->item(0)->textContent)->toContain('New collection');

    collectionOf($user, [$catalog[4]], ['name' => 'Mine 4']);
    expect($cards()->length)->toBe(4)->and($new()->length)->toBe(0);
});
