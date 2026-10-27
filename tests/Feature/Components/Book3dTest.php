<?php

use App\Models\Media;

test('a book shows the media cover on the front face with a spine, a page block and a contact shadow', function () {
    $media = Media::factory()->make(['title' => 'Dune', 'cover_url' => 'https://s4.anilist.co/file/dune.jpg']);

    $html = renderedComponent('<x-book-3d :media="$media" size="lg" />', ['media' => $media]);

    expect(htmlQuery($html, '//span[@data-book-3d][@data-size="lg"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//span[contains(@class,"book3d__front")]//img[@src="https://s4.anilist.co/file/dune.jpg"][@alt=""]')->length)->toBe(1)
        ->and(htmlQuery($html, '//span[contains(@class,"book3d__spine")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//span[contains(@class,"book3d__pages")]')->length)->toBe(3)
        ->and(htmlQuery($html, '//span[contains(@class,"book3d__shadow")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//span[@data-book-3d][@data-broken]')->length)->toBe(0);
});

test('a book without a cover renders the cover fallback with the title and no image', function () {
    $html = renderedComponent('<x-book-3d :media="$media" />', ['media' => Media::factory()->make(['title' => 'No Cover Tale', 'cover_url' => null])]);

    expect(htmlQuery($html, '//span[@data-book-3d][@data-broken]')->length)->toBe(1)
        ->and(htmlQuery($html, '//span[@data-cover-fallback]')->item(0)->textContent)->toContain('No Cover Tale')
        ->and(htmlQuery($html, '//img')->length)->toBe(0);
});

test('src overrides the media, the tilt is the resting yaw, and an unknown size or tilt falls back to the defaults', function () {
    $html = renderedComponent('<x-book-3d src="/cover.png" size="huge" tilt="url(x)" />');

    expect(htmlQuery($html, '//span[@data-book-3d][@data-size="md"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//img[@src="/cover.png"]')->length)->toBe(1)
        ->and($html)->toContain('--tilt: -18deg');

    expect(renderedComponent('<x-book-3d src="/cover.png" tilt="12deg" />'))->toContain('--tilt: 12deg');
});

test('only an interactive book gets the pointer lean', function () {
    expect(renderedComponent('<x-book-3d src="/c.png" interactive />'))->toContain('x-data="book3d(true)"')
        ->and(renderedComponent('<x-book-3d src="/c.png" />'))->toContain('x-data="book3d(false)"');
});

test('the scene prop hands the scene the proxied cover of a media, or the src as given', function () {
    $media = Media::factory()->create(['cover_url' => 'https://s4.anilist.co/file/dune.jpg']);

    $html = renderedComponent('<x-book-3d :media="$media" scene />', ['media' => $media]);

    expect(htmlQuery($html, '//span[@data-book-3d][@data-scene-cover="'.route('covers.show', $media).'"][@x-book-scene]')->length)->toBe(1)
        ->and(htmlQuery(renderedComponent('<x-book-3d src="/cover.png" scene />'), '//span[@data-scene-cover="/cover.png"]')->length)->toBe(1);
});

test('without the scene prop, or without a cover, nothing upgrades the CSS book', function () {
    $media = Media::factory()->create(['cover_url' => null]);

    expect(renderedComponent('<x-book-3d src="/cover.png" />'))->not->toContain('x-book-scene')
        ->and(renderedComponent('<x-book-3d :media="$media" scene />', ['media' => $media]))->not->toContain('x-book-scene');
});
