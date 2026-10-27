<?php

test('it renders the h1 on the page title scale and the description under it', function () {
    $html = renderedComponent('<x-page-header title="Discover" description="Browse the catalog." />');

    expect(htmlQuery($html, '//header/div/h1[normalize-space()="Discover"]')->length)->toBe(1)
        ->and($html)->toContain('text-section')->toContain('md:text-title')->toContain('tracking-tight')->toContain('text-balance')
        ->and($html)->not->toContain('text-hero')
        ->and(htmlQuery($html, '//header//p[normalize-space()="Browse the catalog."]')->length)->toBe(1)
        ->and($html)->toContain('mt-2')->toContain('text-body')->toContain('text-ink-subtle')->toContain('max-w-prose')->toContain('text-pretty');
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
