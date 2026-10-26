<?php

use App\Models\Genre;
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

test('a hero is a labelled carousel whose slides are groups named n of total, each with one h1', function () {
    $html = renderHero();

    expect(htmlQuery($html, '//section[@aria-roledescription="carousel"][@aria-label="Featured stories"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//article[@role="group"][@aria-roledescription="slide"]//h1')->length)->toBe(5)
        ->and(htmlQuery($html, '//article[@aria-label="1 of 5"]//h1')->item(0)->textContent)->toContain('Slide Title 1')
        ->and(htmlQuery($html, '//article[@aria-label="5 of 5"]')->length)->toBe(1);
});

test('the slides sit in a polite live region and only the first is shown', function () {
    $html = renderHero();

    expect(htmlQuery($html, '//div[@aria-live="polite"]/article')->length)->toBe(5)
        ->and(htmlQuery($html, '//article[contains(@style,"display: none")]')->length)->toBe(4);
});

test('titles are split into words inside overflow-hidden masks for the reveal', function () {
    $html = renderHero();

    expect(htmlQuery($html, '//article[@aria-label="1 of 5"]//h1//span[contains(@class,"overflow-hidden")]/span[@data-word]')->length)->toBe(3)
        ->and(trim(preg_replace('/\s+/', ' ', htmlQuery($html, '//article[@aria-label="1 of 5"]//h1')->item(0)->textContent)))->toBe('Slide Title 1');
});

test('the backdrop is the cover: first loaded eagerly, the rest lazily, under a token scrim', function () {
    $html = renderHero();

    expect(htmlQuery($html, '//img[@data-backdrop="0"][@fetchpriority="high"][contains(@class,"object-cover")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//img[@data-backdrop][@loading="lazy"]')->length)->toBe(4)
        ->and(htmlQuery($html, '//img[@data-backdrop][@x-motion-crossfade="fade"]')->length)->toBe(5)
        ->and(htmlQuery($html, '//div[@data-hero-scrim][contains(@class,"from-canvas")]')->length)->toBe(1);
});

test('the eyebrow is the media type and the first genre, then the title and a two-line blurb', function () {
    $slides = heroSlides(2);
    $slides[0]->genres()->attach(Genre::factory()->create(['name' => 'Fantasy']));
    $html = renderHero(slides: $slides->fresh(['mediaType', 'genres']));

    $eyebrow = trim(htmlQuery($html, '//article[@aria-label="1 of 2"]/p[contains(@class,"uppercase")]')->item(0)->textContent);

    expect($eyebrow)->toMatch('/^\w[\w\s]* · Fantasy$/')
        ->and(trim(htmlQuery($html, '//article[@aria-label="2 of 2"]/p[contains(@class,"uppercase")]')->item(0)->textContent))->not->toContain('·')
        ->and(htmlQuery($html, '//article[@aria-label="1 of 2"]/p[contains(@class,"line-clamp-2")]')->length)->toBe(1);
});

test('each slide offers a primary start reading link and a filled add to library button', function () {
    $slides = heroSlides(2);
    $html = renderHero(slides: $slides);

    expect(htmlQuery($html, '//article[@aria-label="2 of 2"]//a[@href="'.route('stories.show', $slides[1]->slug).'"][normalize-space()="Start reading"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//article[@aria-label="2 of 2"]//button[normalize-space()="Add to Library"][contains(@class,"text-cta!")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//article[@aria-label="2 of 2"]//a[contains(@class,"bg-[var(--color-accent)]")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//button[normalize-space()="Add to Library"][contains(@class,"bg-[var(--color-accent)]")]')->length)->toBe(0)
        ->and($html)->toContain('library-add');
});

test('the cta slot replaces the two buttons', function () {
    $html = renderHero(slides: heroSlides(2), slot: '<x-slot:cta><a href="/explore">Browse the catalog</a></x-slot:cta>');

    expect(htmlQuery($html, '//a[normalize-space()="Start reading"]')->length)->toBe(0)
        ->and(htmlQuery($html, '//a[@href="/explore"]')->length)->toBe(2);
});

test('the counter is a large tabular 01 over the total, with a thin progress line', function () {
    $html = renderHero();

    expect(htmlQuery($html, '//p[contains(@class,"tabular-nums")][normalize-space()="01 / 05"]/span[contains(@class,"text-section")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@aria-hidden="true"]//div[contains(@class,"scale-x-(--progress)")]')->length)->toBe(1);
});

test('prev and next are labelled 44px buttons, and there is no pause control or autoplay', function () {
    $html = renderHero();

    foreach (['Previous slide', 'Next slide'] as $label) {
        expect(htmlQuery($html, '//button[@type="button"][@aria-label="'.$label.'"][contains(@class,"size-11")]')->length)->toBe(1);
    }

    expect($html)->not->toContain('slideshow')->not->toContain('interval')->not->toContain('autoplay');
});

test('the next three stories are cards in an up next list, absent on the compact size', function () {
    $html = renderHero();

    expect(htmlQuery($html, '//ul[@aria-label="Up next"]/template[@x-for="card in upcoming"]//button[@type="button"]')->length)->toBe(1)
        ->and($html)->toContain('aspect-[2/3]')
        ->and(htmlQuery(renderHero('size="S"'), '//ul[@aria-label="Up next"]')->length)->toBe(0)
        ->and(renderHero('size="S"'))->toContain('min-h-56');
});

test('size sets the height and title scale', function () {
    expect(renderHero('size="L"'))->toContain('min-h-112')->toContain('md:text-hero')
        ->and(renderHero('size="M"'))->toContain('min-h-80');
});

test('a single media is a hero without cards or controls', function () {
    $html = renderedComponent('<x-hero :media="$media" />', ['media' => heroSlides(1)->first()]);

    expect(htmlQuery($html, '//h1')->length)->toBe(1)
        ->and(htmlQuery($html, '//button[@aria-label]')->length)->toBe(0)
        ->and($html)->not->toContain('Up next')->not->toContain('01 /');
});

test('no slides renders nothing', function () {
    expect(trim(renderedComponent('<x-hero :slides="[]" />')))->toBe('');
});
