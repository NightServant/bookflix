<?php

function renderCta(string $attributes = ''): string
{
    return renderedComponent("<x-cta {$attributes}>Start reading</x-cta>");
}

test('the primary cta is the red flux button with a body label and a 44px floor', function () {
    $html = renderCta('href="/start"');

    expect(htmlQuery($html, '//a[@href="/start"][@data-flux-button][normalize-space()="Start reading"]')->length)->toBe(1)
        ->and($html)->toContain('bg-[var(--color-accent)]')
        ->and($html)->toContain('text-body!')->toContain('px-5!')->toContain('py-2!')->toContain('min-h-11')
        ->and($html)->not->toContain('text-cta!')
        ->and($html)->toContain('focus-ring')->toContain('shadow-none!');
});

test('a cta without an href is a button and keeps wire and alpine attributes', function () {
    $html = renderCta('wire:click="start" @click="go()"');

    expect(htmlQuery($html, '//button[@type="button"][@*[name()="wire:click"]="start"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//a')->length)->toBe(0);
});

test('the secondary cta is not red', function () {
    $html = renderCta('href="/browse" variant="secondary"');

    expect($html)->not->toContain('--color-accent')->toContain('text-body!');
});

test('flux dark mode keeps the brand red as the accent, so primary is red', function () {
    expect(preg_replace('/\s+/', ' ', file_get_contents(resource_path('css/app.css'))))
        ->toContain('.dark { --color-accent: var(--color-brand);');
});
