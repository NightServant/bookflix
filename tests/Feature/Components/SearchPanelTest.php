<?php

function renderSearchPanel(string $attributes = ''): string
{
    return renderedComponent("<x-search-panel {$attributes} />");
}

test('the panel is a search landmark with the question as a real label', function () {
    $html = renderSearchPanel();

    expect(htmlQuery($html, '//search//label[normalize-space()="What would you like to find?"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//search//input[@*[name()="x-combobox:input"]]')->length)->toBe(1);
});

test('the field is a freeform combobox, so the typed text is the search and suggestions only fill it', function () {
    $html = renderSearchPanel(':suggestions="[\'Dune\', \'Berserk\']"');

    expect($html)->toContain('freeform: true')->toContain('multiple: false')
        ->and(htmlQuery($html, '//li[@*[name()="x-combobox:option"]]')->length)->toBe(1)
        ->and($html)->toContain('Dune')->toContain('Berserk')
        ->and(htmlQuery($html, '//p[@role="status"]')->length)->toBe(0);
});

test('the placeholder reaches the input and the model attribute reaches the combobox', function () {
    $html = renderSearchPanel('placeholder="Titles, authors, subjects" wire:model.live.debounce.300ms="q"');

    expect(htmlQuery($html, '//input[@placeholder="Titles, authors, subjects"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@x-combobox][@*[name()="wire:model.live.debounce.300ms"]="q"]')->length)->toBe(1);
});

test('a secondary cta leads to Discover, wherever the navigation config puts it', function () {
    $html = renderSearchPanel();

    expect(htmlQuery($html, '//a[@href="'.route('discover').'"][@data-flux-button][normalize-space()="Browse Discover"]')->length)->toBe(1)
        ->and($html)->not->toContain('--color-accent');

    config(['bookflix.navigation' => [['label' => 'Discover', 'icon' => 'globe-alt', 'route' => 'home', 'active' => []]]]);

    expect(htmlQuery(renderSearchPanel(), '//a[@href="'.route('home').'"][normalize-space()="Browse Discover"]')->length)->toBe(1);
});

test('there is no toggle button to reach, suggestions open as you type', function () {
    expect(htmlQuery(renderSearchPanel(), '//button[@*[name()="x-combobox:button"]][@hidden]')->length)->toBe(1);
});

test('an action turns Enter into a search on that page, and the secondary cta can be left out', function () {
    $html = renderSearchPanel('action="/search" :browse="false"');

    expect(htmlQuery($html, '//search[@x-data="{ q: \'\' }"][contains(@*[name()="x-on:keydown.enter.capture"],"Livewire.navigate")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@x-combobox][@x-model="q"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//a[@data-flux-button]')->length)->toBe(0)
        ->and(htmlQuery(renderSearchPanel(), '//search[@x-data]')->length)->toBe(0);
});

test('the field is capped at 672px so it never stretches across a wide page', function () {
    expect(htmlQuery(renderSearchPanel(), '//search/div[contains(@class,"sm:max-w-2xl")][contains(@class,"sm:flex-1")]')->length)->toBe(1);
});
