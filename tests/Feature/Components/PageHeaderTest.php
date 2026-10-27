<?php

test('it renders the h1 and the description straight after it', function () {
    $html = renderedComponent('<x-page-header title="Discover" description="Browse the catalog." />');

    // The type scale, spacing and measure are asserted in a real browser: tests/Browser/Components/PageHeaderTest.php.
    expect(htmlQuery($html, '//header/div/h1[normalize-space()="Discover"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//header//h1')->length)->toBe(1)
        ->and(htmlQuery($html, '//header/div/h1/following-sibling::p[normalize-space()="Browse the catalog."]')->length)->toBe(1);
});

test('the description is required', function () {
    renderedComponent('<x-page-header title="Discover" />');
})->throws(Exception::class);

test('an eyebrow sits above the title', function () {
    $html = renderedComponent('<x-page-header title="Monster" description="A story." eyebrow="Manga" />');

    expect(strpos($html, 'Manga'))->toBeLessThan(strpos($html, '<h1'));
});

test('without an eyebrow or actions neither renders', function () {
    $html = renderedComponent('<x-page-header title="Monster" description="A story." />');

    expect(htmlQuery($html, '//header/p')->length)->toBe(0)
        ->and(htmlQuery($html, '//header/div[contains(@class,"shrink-0")]')->length)->toBe(0);
});

test('the actions slot renders beside the title', function () {
    $html = renderedComponent('<x-page-header title="Providers" description="Sync sources."><x-slot:actions><a href="/x">Sync all</a></x-slot:actions></x-page-header>');

    expect(htmlQuery($html, '//header/div[contains(@class,"shrink-0")]/a[@href="/x"]')->length)->toBe(1);
});
