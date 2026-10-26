<?php

use App\Models\Media;

function seriesMedia(array $attributes = []): Media
{
    return Media::factory()->manga()->create([
        'title' => 'Frankenstein',
        'cover_url' => 'https://covers.test/frankenstein.jpg',
        'publication_year' => 1818,
        ...$attributes,
    ]);
}

function renderSeriesItem(Media $media, string $attributes = ''): string
{
    return renderedComponent("<x-series-item :media=\"\$media\" {$attributes} />", ['media' => $media]);
}

/**
 * The bracketed index of a rendered item, or null when it has none.
 */
function seriesIndex(string $html): ?string
{
    $index = htmlQuery($html, '//*[@data-index]')->item(0);

    return $index === null ? null : trim($index->textContent);
}

test('a position becomes a bracketed index with a two-digit whole part and no trailing zeros', function (string|int|float $position, string $index) {
    expect(seriesIndex(renderSeriesItem(seriesMedia(), ':position="'.var_export($position, true).'"')))->toBe($index);
})->with([
    'whole number' => [1, '[01]'],
    'decimal column value' => ['1.00', '[01]'],
    'half volume' => [1.5, '[01.5]'],
    'decimal column half volume' => ['1.50', '[01.5]'],
    'two decimals' => ['12.25', '[12.25]'],
    'two digits' => [10, '[10]'],
    'three digits' => [100, '[100]'],
    'zero is a volume too' => [0, '[00]'],
]);

test('without a position the label stands in, and the position wins when both are given', function () {
    expect(seriesIndex(renderSeriesItem(seriesMedia(), 'label="Omnibus"')))->toBe('[Omnibus]')
        ->and(seriesIndex(renderSeriesItem(seriesMedia(), ':position="2" label="Volume Two"')))->toBe('[02]');
});

test('with neither, there is no bracket at all', function () {
    $html = renderSeriesItem(seriesMedia());

    expect(seriesIndex($html))->toBeNull()
        ->and(seriesIndex(renderSeriesItem(seriesMedia(['slug' => 'blank']), ':position="null" label=""')))->toBeNull();
});

test('the index is set in tabular figures', function () {
    expect(htmlQuery(renderSeriesItem(seriesMedia(), ':position="1"'), '//*[@data-index][contains(@class,"tabular-nums")]')->length)->toBe(1);
});

test('the cover is a decorative 2:3 link and the title is the one link that is read', function () {
    $html = renderSeriesItem(seriesMedia(), ':position="1"');
    $story = route('stories.show', 'frankenstein');

    expect(htmlQuery($html, '//a[@href="'.$story.'"][@aria-hidden="true"][@tabindex="-1"][contains(@class,"aspect-[2/3]")]//img[@src="https://covers.test/frankenstein.jpg"][@alt=""][@loading="lazy"][@width][@height]')->length)->toBe(1)
        ->and(htmlQuery($html, '//a[@href="'.$story.'"][@*[name()="wire:navigate"]][normalize-space()="Frankenstein"][contains(@class,"focus-ring")][contains(@class,"min-h-11")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//a[not(@aria-hidden)]')->length)->toBe(1);
});

test('the year follows the title and is left out when unknown', function () {
    expect(htmlQuery(renderSeriesItem(seriesMedia()), '//*[@data-year][normalize-space()="1818"]')->length)->toBe(1)
        ->and(htmlQuery(renderSeriesItem(seriesMedia(['slug' => 'no-year', 'publication_year' => null])), '//*[@data-year]')->length)->toBe(0);
});

test('the order is index, cover, title, year', function () {
    $html = renderSeriesItem(seriesMedia(), ':position="1"');

    expect(preg_replace('/\s+/', ' ', trim(htmlQuery($html, '//div[1]')->item(0)->textContent)))->toBe('[01] Frankenstein 1818')
        ->and(strpos($html, 'data-index'))->toBeLessThan(strpos($html, '<img'))
        ->and(strpos($html, '<img'))->toBeLessThan(strpos($html, 'Frankenstein'))
        ->and(strpos($html, 'Frankenstein'))->toBeLessThan(strpos($html, 'data-year'));
});

test('a cover-less volume shows a placeholder', function () {
    $html = renderSeriesItem(seriesMedia(['cover_url' => null]));

    expect(htmlQuery($html, '//img')->length)->toBe(0)
        ->and(htmlQuery($html, '//*[@data-cover-fallback]')->length)->toBe(1);
});

test('attributes land on the item', function () {
    expect(htmlQuery(renderSeriesItem(seriesMedia(), 'data-test="volume" class="border-b"'), '//div[@data-test="volume"][contains(@class,"border-b")]')->length)->toBe(1);
});

test('an aligned item without an index keeps the index column so covers line up', function () {
    $html = renderedComponent('<x-series-item :media="$media" aligned />', ['media' => seriesMedia()]);

    expect(htmlQuery($html, '//*[@data-index]')->length)->toBe(0)
        ->and(htmlQuery($html, '//span[@aria-hidden="true"][contains(@class,"min-w-16")]')->length)->toBe(1)
        ->and(htmlQuery(renderSeriesItem(seriesMedia()), '//span[contains(@class,"min-w-16")]')->length)->toBe(0);
});
