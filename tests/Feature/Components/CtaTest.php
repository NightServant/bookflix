<?php

function renderCta(string $attributes = ''): string
{
    return renderedComponent("<x-cta {$attributes}>Start reading</x-cta>");
}

test('the primary cta is a link with its label as the accessible name', function () {
    $html = renderCta('href="/start"');

    // How it looks (red, 16px, 44px, flat, focus ring) is asserted in a real browser: tests/Browser/Components/CtaTest.php.
    expect(htmlQuery($html, '//a[@href="/start"][@data-flux-button][normalize-space()="Start reading"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//a/svg')->length)->toBe(0);
});

test('a cta without an href is a button and keeps wire and alpine attributes', function () {
    $html = renderCta('wire:click="start" @click="go()"');

    expect(htmlQuery($html, '//button[@type="button"][@*[name()="wire:click"]="start"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//a')->length)->toBe(0);
});

test('every variant renders the same labelled link', function (string $variant) {
    $html = renderCta('href="/browse" variant="'.$variant.'"');

    expect(htmlQuery($html, '//a[@href="/browse"][@data-flux-button][normalize-space()="Start reading"]')->length)->toBe(1);
})->with(['primary', 'secondary', 'danger']);

test('flux dark mode keeps the brand red as the accent, so primary is red', function () {
    expect(preg_replace('/\s+/', ' ', file_get_contents(resource_path('css/app.css'))))
        ->toContain('.dark { --color-accent: var(--color-brand);');
});

test('a leading icon renders a size-5 outline icon before the label', function () {
    $html = renderCta('href="/go" icon="book-open"');

    expect(htmlQuery($html, '//a[@data-flux-button]/svg[@data-flux-icon]')->length)->toBe(1)
        ->and(htmlQuery($html, '//a/span[normalize-space()="Start reading"]')->length)->toBe(1)
        ->and(strpos($html, '<svg'))->toBeLessThan(strpos($html, 'Start reading'));
});

test('a trailing icon renders after the label', function () {
    $html = renderCta('href="/go" icon:trailing="arrow-right"');

    expect(htmlQuery($html, '//a[@data-flux-button]/svg[@data-flux-icon]')->length)->toBe(1)
        ->and(strpos($html, '<svg'))->toBeGreaterThan(strpos($html, 'Start reading'));
});

test('icons are decorative and a cta without one has no svg', function () {
    expect(renderCta('href="/go" icon="plus"'))->toContain('aria-hidden="true"')
        ->and(htmlQuery(renderCta('href="/go"'), '//svg')->length)->toBe(0);
});

test('the lucide compass is available as a flux icon', function () {
    $html = renderedComponent('<x-cta variant="secondary" href="/discover" icon="compass">Browse Discover</x-cta>');

    expect(htmlQuery($html, '//a/svg[@data-flux-icon]//circle')->length)->toBe(1);
});

test('an icon-only cta is a button that keeps its aria-label and has no text', function () {
    $html = renderedComponent('<x-cta icon="x-mark" aria-label="Clear filters" wire:click="clear" />');

    expect(htmlQuery($html, '//button[@aria-label="Clear filters"]/svg')->length)->toBe(1)
        ->and(htmlQuery($html, '//button[@data-flux-button][@aria-label="Clear filters"]')->length)->toBe(1)
        ->and(trim(strip_tags(htmlQuery($html, '//button')->item(0)->textContent)))->toBe('');
});

test('an icon-only cta without an aria-label is refused', function () {
    renderedComponent('<x-cta icon="x-mark" wire:click="clear" />');
})->throws(Exception::class);
