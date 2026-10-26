<?php

use App\Models\Media;
use Illuminate\Support\Collection;

/**
 * @return Collection<int, Media>
 */
function heroSlides(int $count = 5): Collection
{
    return Media::factory()->count($count)->sequence(fn ($sequence) => [
        'title' => 'Slide Title '.($sequence->index + 1),
        'cover_url' => 'https://covers.test/'.($sequence->index + 1).'.jpg',
    ])->create();
}

function renderHero(string $attributes = '', ?Collection $slides = null, string $slot = ''): string
{
    return renderedComponent("<x-hero :slides=\"\$slides\" {$attributes}>{$slot}</x-hero>", ['slides' => $slides ?? heroSlides()]);
}

test('a hero is a carousel with one h1 per slide and slide groups labelled n of total', function () {
    $html = renderHero();

    expect(htmlQuery($html, '//section[@aria-roledescription="carousel"][@aria-label="Featured stories"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//article[@role="group"][@aria-roledescription="slide"]//h1')->length)->toBe(5)
        ->and(htmlQuery($html, '//article[@aria-label="1 of 5"]//h1[normalize-space()="Slide Title 1"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//article[@aria-label="5 of 5"]')->length)->toBe(1);
});

test('only the first slide is shown, with its cover loaded first and the rest lazily', function () {
    $html = renderHero();

    expect(htmlQuery($html, '//article[contains(@style,"display: none")]')->length)->toBe(4)
        ->and(htmlQuery($html, '//article[@aria-label="1 of 5"][not(@style)]//img[@fetchpriority="high"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//article//img[@loading="lazy"]')->length)->toBe(4);
});

test('the counter reads 01 / 05 in tabular figures', function () {
    $html = renderHero();

    expect(htmlQuery($html, '//p[contains(@class,"tabular-nums")][normalize-space()="01 / 05"]')->length)->toBe(1);
});

test('prev, next and pause are labelled 44px buttons ahead of the slides in the tab order', function () {
    $html = renderHero();

    foreach (['Play slideshow', 'Previous slide', 'Next slide'] as $label) {
        expect(htmlQuery($html, '//button[@type="button"][@aria-label="'.$label.'"][contains(@class,"size-11")]')->length)->toBe(1);
    }

    expect(strpos($html, 'Play slideshow'))->toBeLessThan(strpos($html, 'Slide Title 1'));
});

test('the slides announce politely and crossfade with the motion directive', function () {
    $html = renderHero();

    expect(htmlQuery($html, '//div[@aria-live="polite"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//article[@x-motion-crossfade="current"]')->length)->toBe(5);
});

test('autoplay is off unless asked for', function () {
    expect(renderHero())->toContain('hero(5, false, 6000)')
        ->and(renderHero('autoplay :interval="3000"'))->toContain('hero(5, true, 3000)')->toContain('aria-label="Pause slideshow"');
});

test('the eyebrow and size classes apply', function () {
    $html = renderHero('eyebrow="Trending this week" size="S"');

    expect(htmlQuery($html, '//p[contains(@class,"uppercase")][normalize-space()="Trending this week"]')->length)->toBe(5)
        ->and($html)->toContain('min-h-56')->not->toContain('min-h-112')
        ->and(renderHero('size="L"'))->toContain('min-h-112')->toContain('md:text-hero');
});

test('each slide links its cta to the story, or the cta slot replaces it', function () {
    $slides = heroSlides(2);
    $html = renderHero(slides: $slides);

    expect(htmlQuery($html, '//a[@href="'.route('stories.show', $slides[1]->slug).'"][normalize-space()="View story"]')->length)->toBe(1);

    $custom = renderHero(slides: $slides, slot: '<x-slot:cta><a href="/explore">Browse the catalog</a></x-slot:cta>');

    expect(htmlQuery($custom, '//a[normalize-space()="View story"]')->length)->toBe(0)
        ->and(htmlQuery($custom, '//a[@href="/explore"]')->length)->toBe(2);
});

test('a single media is a hero without carousel controls', function () {
    $html = renderedComponent('<x-hero :media="$media" />', ['media' => heroSlides(1)->first()]);

    expect(htmlQuery($html, '//h1')->length)->toBe(1)
        ->and(htmlQuery($html, '//button')->length)->toBe(0)
        ->and($html)->not->toContain('01 /');
});

test('no slides renders nothing', function () {
    expect(trim(renderedComponent('<x-hero :slides="[]" />')))->toBe('');
});
