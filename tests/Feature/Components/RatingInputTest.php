<?php

function renderRatingInput(string $attributes = 'wire:model="rating"'): string
{
    return renderedComponent("<x-rating-input {$attributes} />");
}

test('the input is a radio group named for what it rates, with all ten scores in order', function () {
    $html = renderRatingInput();

    expect(htmlQuery($html, '//*[@role="radiogroup"][@aria-label="Your rating"]')->length)->toBe(1)
        ->and(collect(htmlQuery($html, '//*[@role="radiogroup"]//button[@role="radio"]'))->map(fn (DOMElement $radio): string => $radio->getAttribute('data-value').':'.$radio->getAttribute('aria-label'))->all())
        ->toBe(array_map(fn (int $score): string => "{$score}:{$score} out of 10", range(1, 10)));
});

test('five stars hold two halves each, the left one the odd score', function () {
    $html = renderRatingInput();
    $cells = htmlQuery($html, '//*[@role="radiogroup"]/*[@role="none"]');

    expect($cells->length)->toBe(5);

    foreach ($cells as $index => $cell) {
        $values = collect($cell->getElementsByTagName('button'))->map(fn (DOMElement $radio): string => $radio->getAttribute('data-value'))->all();

        expect($values)->toBe([(string) ($index * 2 + 1), (string) ($index * 2 + 2)]);
    }
});

test('the targets are 44px tall and 24px wide at least, buttons that never submit a form', function () {
    $html = renderRatingInput();

    expect(htmlQuery($html, '//*[@role="radiogroup"]/*[@role="none"][contains(@class,"h-11")][contains(@class,"w-12")]')->length)->toBe(5)
        ->and(htmlQuery($html, '//button[@role="radio"][@type="button"][contains(@class,"inset-y-0")][contains(@class,"w-1/2")]')->length)->toBe(10);
});

test('the stars are decorative and the keyboard focus shows on the star', function () {
    $html = renderRatingInput();

    expect(htmlQuery($html, '//*[@role="none"]//*[@aria-hidden="true"]//svg')->length)->toBeGreaterThanOrEqual(10)
        ->and(htmlQuery($html, '//*[@role="radiogroup"]/*[@role="none"][contains(@class,"has-focus-visible:ring-2")][contains(@class,"has-focus-visible:ring-accent-electric")]')->length)->toBe(5);
});

test('the model attribute reaches the root, which is the Alpine component that exposes the score', function () {
    $html = renderRatingInput('wire:model="rating" data-test="own" class="order-last"');

    expect(htmlQuery($html, '//*[@*[name()="x-data"]][@*[name()="x-modelable"]="value"][@*[name()="wire:model"]="rating"][@data-test="own"][contains(@class,"order-last")]')->length)->toBe(1);
});

test('the visible label and the hint can be changed', function () {
    $html = renderRatingInput('wire:model="rating" label="Rate Dune" hint="Tap a star"');

    expect(htmlQuery($html, '//*[@role="radiogroup"][@aria-label="Rate Dune"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//span[normalize-space()="Rate Dune"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@aria-hidden="true"][normalize-space()="Tap a star"]')->length)->toBe(1);
});

test('a rating can be cleared with a 44px button that is hidden until there is one', function () {
    $html = renderRatingInput();
    $clear = htmlQuery($html, '//button[@aria-label="Clear your rating"]');

    expect($clear->length)->toBe(1)
        ->and($clear->item(0)->getAttribute('type'))->toBe('button')
        ->and($clear->item(0)->getAttribute('class'))->toContain('min-h-11')
        ->and($clear->item(0)->hasAttribute('x-cloak'))->toBeTrue()
        ->and(trim($clear->item(0)->textContent))->toBe('Clear');
});

test('a change is announced politely to screen readers', function () {
    expect(htmlQuery(renderRatingInput(), '//*[@role="status"][contains(@class,"sr-only")]')->length)->toBe(1);
});
