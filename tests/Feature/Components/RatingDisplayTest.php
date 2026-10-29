<?php

function renderRating(string $attributes): string
{
    return renderedComponent("<x-rating-display {$attributes} />");
}

/**
 * The fill of each of the five stars, left to right.
 *
 * @return list<string>
 */
function starFills(string $html): array
{
    return collect(htmlQuery($html, '//*[@data-star]'))->map(fn ($star) => $star->getAttribute('data-star'))->all();
}

test('the rating reads as text for screen readers, and the stars and numbers are hidden from them', function () {
    $html = renderRating(':average="4.5" :count="1234"');

    expect(htmlQuery($html, '//*[contains(@class,"sr-only")][normalize-space()="Rated 4.5 out of 5 from 1,234 ratings"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@aria-hidden="true"]//*[@data-star]')->length)->toBe(5)
        ->and(htmlQuery($html, '//*[@aria-hidden="true"][contains(normalize-space(),"4.5")][contains(normalize-space(),"1,234")]')->length)->toBeGreaterThan(0);
});

test('the zero to five average becomes five stars with half stars', function (float|string $average, array $fills) {
    expect(starFills(renderRating(':average="'.var_export($average, true).'" :count="10"')))->toBe($fills);
})->with([
    'five is five full stars' => [5, ['full', 'full', 'full', 'full', 'full']],
    'four and a half' => [4.5, ['full', 'full', 'full', 'full', 'half']],
    'three and a half' => ['3.50', ['full', 'full', 'full', 'half', 'empty']],
    'three' => [3, ['full', 'full', 'full', 'empty', 'empty']],
    'a half star' => [0.5, ['half', 'empty', 'empty', 'empty', 'empty']],
    'zero is none' => [0, ['empty', 'empty', 'empty', 'empty', 'empty']],
    '3.2 rounds to the nearest half star down' => ['3.20', ['full', 'full', 'full', 'empty', 'empty']],
    '3.25 rounds up' => ['3.25', ['full', 'full', 'full', 'half', 'empty']],
]);

test('the decimal column value works, and the number shown is out of five', function () {
    $html = renderRating('average="4.40" :count="3"');

    expect(htmlQuery($html, '//*[contains(@class,"sr-only")][normalize-space()="Rated 4.4 out of 5 from 3 ratings"]')->length)->toBe(1)
        ->and(starFills($html))->toBe(['full', 'full', 'full', 'full', 'half']);
});

test('one rating is singular and thousands are separated', function () {
    expect(renderRating(':average="4" :count="1"'))->toContain('Rated 4.0 out of 5 from 1 rating<')
        ->and(renderRating(':average="4" :count="1234567"'))->toContain('from 1,234,567 ratings');
});

test('an average outside the scale is held to it', function () {
    expect(starFills(renderRating(':average="7" :count="2"')))->toBe(['full', 'full', 'full', 'full', 'full'])
        ->and(renderRating(':average="7" :count="2"'))->toContain('Rated 5.0 out of 5')
        ->and(starFills(renderRating(':average="-3" :count="2"')))->toBe(['empty', 'empty', 'empty', 'empty', 'empty']);
});

test('with no ratings it says so and draws no stars', function (string $attributes) {
    $html = renderRating($attributes);

    expect(htmlQuery($html, '//p[normalize-space()="No ratings yet"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@data-star]')->length)->toBe(0)
        ->and($html)->not->toContain('Rated');
})->with([
    'no count' => [':average="4.5" :count="0"'],
    'no average' => [':average="null" :count="5"'],
    'nothing given' => [''],
]);

test('attributes land on the rating', function () {
    expect(htmlQuery(renderRating(':average="4.5" :count="4" data-test="rating" class="mt-2"'), '//*[@data-test="rating"][contains(@class,"mt-2")]')->length)->toBe(1)
        ->and(htmlQuery(renderRating('data-test="rating"'), '//*[@data-test="rating"]')->length)->toBe(1);
});
