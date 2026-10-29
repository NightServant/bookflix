<?php

function renderTabs(string $attributes = ''): string
{
    return renderedComponent(<<<BLADE
        <x-tabs :tabs="['about' => 'About', 'editions' => 'Editions', 'reviews' => 'Reviews']" label="Story details" {$attributes}>
            <x-slot:about>About panel</x-slot:about>
            <x-slot:editions>Editions panel</x-slot:editions>
            <x-slot:reviews>Reviews panel</x-slot:reviews>
        </x-tabs>
        BLADE);
}

test('tabs render a labelled tablist of tab buttons with a tabpanel each', function () {
    $html = renderTabs();

    expect(htmlQuery($html, '//*[@role="tablist"][@aria-label="Story details"]//button[@role="tab"]')->length)->toBe(3)
        ->and(htmlQuery($html, '//*[@role="tabpanel"]')->length)->toBe(3)
        ->and(htmlQuery($html, '//*[@role="tabpanel"][normalize-space()="Editions panel"]')->length)->toBe(1);
});

test('each tab controls its panel and each panel is labelled by its tab', function () {
    $html = renderTabs();

    $tab = htmlQuery($html, '//button[@role="tab"][normalize-space()="Editions"]')->item(0);
    $panel = htmlQuery($html, '//*[@role="tabpanel"][normalize-space()="Editions panel"]')->item(0);

    expect($tab->getAttribute('aria-controls'))->toBe($panel->getAttribute('id'))
        ->and($panel->getAttribute('aria-labelledby'))->toBe($tab->getAttribute('id'));
});

test('the first tab starts selected, or the one named by selected, and the others start hidden', function () {
    $first = renderTabs();
    $named = renderTabs('selected="reviews"');

    expect(htmlQuery($first, '//button[@role="tab"][@aria-selected="true"]')->item(0)->textContent)->toContain('About')
        ->and(htmlQuery($first, '//button[@role="tab"][@aria-selected="false"]')->length)->toBe(2)
        ->and(htmlQuery($first, '//*[@role="tabpanel"][contains(@style,"display: none")]')->length)->toBe(2)
        ->and(htmlQuery($named, '//button[@role="tab"][@aria-selected="true"]')->item(0)->textContent)->toContain('Reviews')
        ->and($named)->toContain('default-index="2"');
});

test('tabs are 44px targets with a focus ring and a panel that can take focus', function () {
    $html = renderTabs();

    expect(htmlQuery($html, '//button[@role="tab"][contains(@class,"min-h-11")][contains(@class,"focus-ring")]')->length)->toBe(3)
        ->and(htmlQuery($html, '//*[@role="tabpanel"][contains(@class,"focus-ring")]')->length)->toBe(3);
});

test('attributes land on the tabs root', function () {
    expect(htmlQuery(renderTabs('data-test="story-tabs"'), '//div[@x-tabs][@data-test="story-tabs"]')->length)->toBe(1);
});
