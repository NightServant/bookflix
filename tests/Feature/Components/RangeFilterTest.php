<?php

function renderRange(string $attributes = ''): string
{
    return renderedComponent("<x-range-filter label=\"Publication year\" :min=\"1900\" :max=\"2025\" {$attributes} />");
}

test('a range filter has a label, a live range readout and a slider mount that livewire leaves alone', function () {
    $html = renderRange();

    expect(htmlQuery($html, '//span[normalize-space()="Publication year"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//output[normalize-space()="1900 – 2025"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@*[name()="wire:ignore"]]//*[@x-ref="mount"]')->length)->toBe(1);
});

test('the slider is set up from the bounds, the step and handle names', function () {
    $html = renderRange('step="5"');

    expect($html)->toContain('rangeFilter(')
        ->and($html)->toContain('min: 1900')->toContain('max: 2025')->toContain('step: 5')
        ->and($html)->toContain('Publication year, minimum')->toContain('Publication year, maximum');
});

test('the two values are the model: wire:model.live lands on the element that is modelable', function () {
    $html = renderRange('wire:model.live="years"');

    expect(htmlQuery($html, '//*[@x-modelable="values"][@*[name()="wire:model.live"]="years"]')->length)->toBe(1);
});

test('the step defaults to one and other attributes land on the root', function () {
    $html = renderRange('data-test="years" class="mt-4"');

    expect($html)->toContain('step: 1')
        ->and(htmlQuery($html, '//*[@x-modelable][@data-test="years"][contains(@class,"mt-4")]')->length)->toBe(1);
});
