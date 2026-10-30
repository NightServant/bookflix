<?php

use Livewire\Livewire;
use Tests\Fixtures\Livewire\ViewToggleFixture;

function renderViewToggle(string $attributes = 'wire:model.live="view"'): string
{
    return renderedComponent("<x-view-toggle {$attributes} />");
}

test('the toggle is a radio group of two options, grid then list, each with a visible label', function () {
    $html = renderViewToggle();

    expect(htmlQuery($html, '//*[@*[name()="x-radio"]]//*[@*[name()="x-radio:option"]]')->length)->toBe(2)
        ->and(collect(htmlQuery($html, '//*[@*[name()="x-radio:option"]]'))->map(fn ($option) => $option->getAttribute('value').':'.trim($option->textContent))->all())->toBe(['grid:Grid', 'list:List']);
});

test('a page with shelves asks for a third option, after list', function () {
    $html = renderViewToggle('wire:model.live="view" :shelves="true"');

    expect(collect(htmlQuery($html, '//*[@*[name()="x-radio:option"]]'))->map(fn ($option) => $option->getAttribute('value').':'.trim($option->textContent))->all())->toBe(['grid:Grid', 'list:List', 'shelves:Shelves'])
        ->and(htmlQuery($html, '//button[@*[name()="x-radio:option"]][contains(@class,"min-h-11")]//svg[@aria-hidden="true"]')->length)->toBe(3);
});

test('the group is named, and the model attribute reaches the radio root', function () {
    $html = renderViewToggle();

    expect(htmlQuery($html, '//*[@*[name()="x-radio"]][@aria-label="Layout"][@*[name()="wire:model.live"]="view"]')->length)->toBe(1)
        ->and(htmlQuery(renderViewToggle('label="Results layout" wire:model="view"'), '//*[@*[name()="x-radio"]][@aria-label="Results layout"][@*[name()="wire:model"]="view"]')->length)->toBe(1);
});

test('the options are 44px targets with the shared focus ring and decorative icons', function () {
    $html = renderViewToggle();

    expect(htmlQuery($html, '//button[@*[name()="x-radio:option"]][contains(@class,"min-h-11")][contains(@class,"focus-ring")][@type="button"]')->length)->toBe(2)
        ->and(htmlQuery($html, '//button[@*[name()="x-radio:option"]]//svg[@aria-hidden="true"]')->length)->toBe(2);
});

test('attributes other than the model land on the group', function () {
    expect(htmlQuery(renderViewToggle('wire:model.live="view" data-test="toggle" class="ms-auto"'), '//*[@*[name()="x-radio"]][@data-test="toggle"][contains(@class,"ms-auto")]')->length)->toBe(1);
});

test('the choice is kept in the url of the livewire parent', function () {
    Livewire::component('view-toggle-fixture', ViewToggleFixture::class);

    Livewire::test(ViewToggleFixture::class)
        ->assertSet('view', 'grid')
        ->set('view', 'list')
        ->assertSet('view', 'list')
        ->assertSeeHtml('data-test="view">list<');

    Livewire::withQueryParams(['view' => 'list'])
        ->test(ViewToggleFixture::class)
        ->assertSet('view', 'list');
});
