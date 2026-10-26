<?php

/**
 * @return list<array{label: string, route: string, icon: string}>
 */
function quickLinks(): array
{
    return [
        ['label' => 'Continue reading', 'route' => 'home', 'icon' => 'book-open'],
        ['label' => 'New releases', 'route' => 'explore.index', 'icon' => 'sparkles'],
        ['label' => 'Browse by subject', 'route' => 'landing', 'icon' => 'squares-2x2'],
    ];
}

function renderQuickLinks(string $attributes = ''): string
{
    return renderedComponent("<x-quick-links :links=\"\$links\" {$attributes} />", ['links' => quickLinks()]);
}

test('quick links are a labelled navigation holding one list item per link, in the order given', function () {
    $html = renderQuickLinks();

    expect(htmlQuery($html, '//nav[@aria-label="Quick links"]//ul/li')->length)->toBe(3)
        ->and(collect(htmlQuery($html, '//nav//li'))->map(fn ($li) => trim(preg_replace('/\s+/', ' ', $li->textContent)))->all())
        ->toBe(['Continue reading', 'New releases', 'Browse by subject']);
});

test('each link points at its route and carries its icon', function () {
    $html = renderQuickLinks();

    foreach (quickLinks() as $link) {
        expect(htmlQuery($html, '//li//a[@href="'.route($link['route']).'"][@*[name()="wire:navigate"]]//*[@data-flux-card]//svg[@data-flux-icon]')->length)->toBe(1);
    }
});

test('the links are flux cards that fill their cell, so a row has equal heights', function () {
    $html = renderQuickLinks();

    expect(htmlQuery($html, '//ul[contains(@class,"auto-rows-fr")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//li/a[contains(@class,"h-full")]/*[@data-flux-card][contains(@class,"h-full")]')->length)->toBe(3);
});

test('every link is a 44px target with the shared focus ring and every icon is decorative', function () {
    $html = renderQuickLinks();

    expect(htmlQuery($html, '//a[contains(@class,"focus-ring")]//*[contains(@class,"min-h-11")]')->length)->toBe(3)
        ->and(htmlQuery($html, '//svg[@aria-hidden="true"]')->length)->toBe(3);
});

test('the navigation label can be changed', function () {
    expect(htmlQuery(renderQuickLinks('label="Shortcuts"'), '//nav[@aria-label="Shortcuts"]')->length)->toBe(1);
});

test('attributes land on the navigation', function () {
    expect(htmlQuery(renderQuickLinks('data-test="links" class="mt-6"'), '//nav[@data-test="links"][contains(@class,"mt-6")]')->length)->toBe(1);
});
