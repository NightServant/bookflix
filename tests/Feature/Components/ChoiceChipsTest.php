<?php

function renderChoices(string $attributes = 'wire:model="picked"', array $options = ['manga' => 'Manga', 'novel' => 'Novel']): string
{
    return renderedComponent("<x-choice-chips legend=\"Formats\" :options=\"\$options\" {$attributes} />", ['options' => $options]);
}

test('the chips are checkboxes in a fieldset named by a legend, one per option, valued by the key', function () {
    $html = renderChoices();

    expect(htmlQuery($html, '//fieldset/legend[normalize-space()="Formats"]')->length)->toBe(1)
        ->and(collect(htmlQuery($html, '//label//input[@type="checkbox"]'))->map(fn ($input) => $input->getAttribute('value'))->all())->toBe(['manga', 'novel'])
        ->and(collect(htmlQuery($html, '//label'))->map(fn ($label) => trim($label->textContent))->all())->toBe(['Manga', 'Novel']);
});

test('the model attribute reaches every checkbox, and the rest land on the fieldset', function () {
    $html = renderChoices('wire:model="picked" data-test="formats" class="mt-4"');

    expect(htmlQuery($html, '//input[@type="checkbox"][@*[name()="wire:model"]="picked"]')->length)->toBe(2)
        ->and(htmlQuery($html, '//fieldset[@data-test="formats"][contains(@class,"mt-4")]')->length)->toBe(1);
});

test('a chip is a 44px target whose focus ring and checked state are drawn from the native checkbox', function () {
    $html = renderChoices();

    expect(htmlQuery($html, '//label/input[contains(@class,"peer")][contains(@class,"sr-only")]')->length)->toBe(2)
        ->and(htmlQuery($html, '//label/span[contains(@class,"min-h-11")][contains(@class,"peer-checked:bg-ink")][contains(@class,"peer-checked:hover:bg-ink")][contains(@class,"peer-focus-visible:ring-2")]')->length)->toBe(2)
        ->and(htmlQuery($html, '//label//svg[@aria-hidden="true"]')->length)->toBe(2);
});

test('the legend can be hidden from sight and stays for screen readers', function () {
    $html = renderedComponent('<x-choice-chips legend="Subgenres" :hide-legend="true" :options="[\'a\' => \'A\']" wire:model="p" />');

    expect(htmlQuery($html, '//legend[contains(@class,"sr-only")]')->length)->toBe(1);
});

test('names are escaped', function () {
    $html = renderChoices(options: ['x' => '<script>alert(1)</script>']);

    expect($html)->not->toContain('<script>alert(1)</script>')->toContain('&lt;script&gt;');
});
