<?php

use App\Models\Media;
use Illuminate\Support\Collection;

/**
 * @param  Collection<int, Media>|null  $media
 */
function renderShelf(string $attributes = 'title="Trending Now"', ?Collection $media = null): string
{
    return renderedComponent(
        "<x-shelf {$attributes}>@foreach (\$media as \$item)<li wire:key=\"m-{{ \$item->id }}\"><x-cover-tile :media=\"\$item\" /></li>@endforeach</x-shelf>",
        ['media' => $media ?? Media::factory()->count(3)->create()],
    );
}

test('a shelf is a labelled section whose title is its heading', function () {
    $html = renderShelf('title="Staff Picks"');

    expect(htmlQuery($html, '//section[@aria-labelledby="shelf-staff-picks"]//h2[@id="shelf-staff-picks"][normalize-space()="Staff Picks"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//ul//li//a[contains(@href,"stories")]')->length)->toBe(3);
});

test('prev and next are labelled buttons of at least 44px that start out disabled', function () {
    $html = renderShelf();

    expect(htmlQuery($html, '//button[@type="button"][@aria-label="Previous in Trending Now"][@*[name()=":disabled"]="atStart"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//button[@type="button"][@aria-label="Next in Trending Now"][@*[name()=":disabled"]="atEnd"]')->length)->toBe(1)
        ->and(substr_count($html, 'size-11'))->toBe(2)
        ->and($html)->toContain('rounded-card');
});

test('the arrows stay hidden until the track can scroll', function () {
    $html = renderShelf();

    expect(htmlQuery($html, '//div[@x-show="canScroll"][contains(@style,"display: none")]//button')->length)->toBe(2);
});

test('plain shelves sit on the deep surface and only a featured shelf gets the gradient', function () {
    $plain = renderShelf('title="Staff Picks"');
    $featured = renderShelf('title="Trending Now" featured');

    expect($plain)->toContain('bg-surface-deep')->not->toContain('from-featured-from')
        ->and($featured)->toContain('from-featured-from')->toContain('via-featured-via')->toContain('to-featured-to')->not->toContain('bg-surface-deep');
});

test('see all is named after the shelf and only renders with an href', function () {
    expect(renderShelf())->not->toContain('See all');

    $html = renderShelf('title="Staff Picks" href="/explore/813"');

    expect(htmlQuery($html, '//a[@href="/explore/813"][@aria-label="See all in Staff Picks"][normalize-space()="See all"]')->length)->toBe(1);
});

test('the optional eyebrow sits above the title', function () {
    $html = renderShelf('title="Trending Now" eyebrow="This week"');

    expect(htmlQuery($html, '//p[contains(@class,"uppercase")][normalize-space()="This week"]/following-sibling::h2')->length)->toBe(1)
        ->and(renderShelf())->not->toContain('uppercase');
});

test('the track reveals its tiles with motion and has no scrollbar chrome', function () {
    expect(htmlQuery(renderShelf(), '//ul[@x-motion-reveal][contains(@class,"snap-x")][contains(@class,"overflow-x-auto")]')->length)->toBe(1);
});
