<?php

function renderFilterSheet(string $attributes = ''): string
{
    return renderedComponent("<x-filter-sheet {$attributes}><p>Genre filters</p></x-filter-sheet>");
}

test('the sheet is a flyout dialog holding the slot, opened by a trigger that is hidden from the lg breakpoint up', function () {
    $html = renderFilterSheet();

    expect(htmlQuery($html, '//*[contains(@class,"lg:hidden")]//*[@data-flux-modal-trigger]//*[@data-flux-button][normalize-space()="Filters"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@data-flux-modal]/dialog[@data-modal="filters"][@data-flux-flyout]//p[normalize-space()="Genre filters"]')->length)->toBe(1);
});

test('the trigger and the dialog share the name, which can be changed', function () {
    $html = renderFilterSheet('name="search-filters"');

    expect($html)->toContain('modal-show')->toContain('search-filters')
        ->and(htmlQuery($html, '//dialog[@data-modal="search-filters"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//dialog[@data-modal="filters"]')->length)->toBe(0);
});

test('the dialog is named by its visible heading', function () {
    $html = renderFilterSheet();

    $heading = htmlQuery($html, '//dialog//h2[normalize-space()="Filters"]')->item(0);

    expect($heading)->not->toBeNull()
        ->and($heading->getAttribute('id'))->not->toBeEmpty()
        ->and($html)->toContain("closest('dialog').setAttribute('aria-labelledby', '{$heading->getAttribute('id')}')");
});

test('there is a visible 44px close button and a show results cta that closes the sheet', function () {
    $html = renderFilterSheet();

    expect(htmlQuery($html, '//dialog//*[@data-flux-modal-close]//button[@aria-label="Close filters"][contains(@class,"size-11")][contains(@class,"focus-ring")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//dialog//*[@data-flux-modal-close]//*[@data-flux-button][normalize-space()="Show results"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//dialog//button[@aria-label="Close modal"]')->length)->toBe(0);
});

test('tab wraps around inside the sheet', function () {
    $html = renderFilterSheet();

    expect(htmlQuery($html, '//dialog//*[@*[name()="x-on:keydown.tab.prevent"]="$focus.wrap().next()"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//dialog//*[@*[name()="x-on:keydown.shift.tab.prevent"]="$focus.wrap().previous()"]')->length)->toBe(1);
});

test('resizing up to a desktop width closes it, so the page is never left inert behind a hidden dialog', function () {
    expect(renderFilterSheet())->toContain('$flux.modal(')->toContain('innerWidth >= 1024');
});

test('attributes land on the wrapper', function () {
    expect(htmlQuery(renderFilterSheet('data-test="sheet" class="mb-4"'), '//*[@data-test="sheet"][contains(@class,"lg:hidden")][contains(@class,"mb-4")]')->length)->toBe(1);
});
