<?php

use App\Discovery\Search\TaxonomyFacets;
use App\Models\Genre;

function renderGenrePicker(array $families, string $attributes = 'wire:model="genres"'): string
{
    return renderedComponent("<x-genre-picker :families=\"\$families\" {$attributes} />", ['families' => $families]);
}

function pickerFamilies(): array
{
    $fantasy = Genre::factory()->create(['name' => 'Fantasy', 'slug' => 'fantasy']);
    Genre::factory()->childOf($fantasy, 'Portal fantasy')->create(['name' => 'Isekai', 'slug' => 'isekai']);
    Genre::factory()->childOf($fantasy, 'Portal fantasy')->create(['name' => 'Portal Quest', 'slug' => 'portal-quest']);
    Genre::factory()->childOf($fantasy)->create(['name' => 'Epic Fantasy', 'slug' => 'epic-fantasy']);
    Genre::factory()->create(['name' => 'Romance', 'slug' => 'romance']);

    return app(TaxonomyFacets::class)->genres();
}

test('each family is a row with its own checkbox, and its subgenres sit behind a button that says how many there are', function () {
    $html = renderGenrePicker(pickerFamilies());

    expect(htmlQuery($html, '//ul/li')->length)->toBeGreaterThanOrEqual(2)
        ->and(htmlQuery($html, '//input[@type="checkbox"][@value="fantasy"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//input[@type="checkbox"][@value="romance"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//button[@aria-expanded][@aria-controls]')->length)->toBe(1) // romance has no subgenres, so no button
        ->and(preg_replace('/\s+/', ' ', htmlQuery($html, '//button[@aria-expanded]')->item(0)->textContent))->toContain('3 subgenres');
});

test('subgenres are grouped under their display group names and valued by slug', function () {
    $html = renderGenrePicker(pickerFamilies());

    expect(collect(htmlQuery($html, '//li[.//input[@value="fantasy"]]//fieldset//input[@type="checkbox"]'))->map(fn ($input) => $input->getAttribute('value'))->sort()->values()->all())->toBe(['epic-fantasy', 'isekai', 'portal-quest'])
        ->and(htmlQuery($html, '//legend[normalize-space()="Portal fantasy"]')->length)->toBe(1);
});

test('the model attribute reaches every checkbox', function () {
    $html = renderGenrePicker(pickerFamilies());

    expect(htmlQuery($html, '//input[@type="checkbox"][not(@*[name()="wire:model"]="genres")]')->length)->toBe(0)
        ->and(htmlQuery($html, '//input[@type="checkbox"]')->length)->toBe(5);
});

test('the disclosure is a 44px button with a labelled state, and the panel starts closed unless it holds a choice', function () {
    $families = pickerFamilies();
    $html = renderGenrePicker($families);
    $open = renderedComponent('<x-genre-picker :families="$families" :open="[\'fantasy\']" wire:model="genres" />', ['families' => $families]);

    expect(htmlQuery($html, '//button[@aria-expanded][contains(@class,"min-h-11")][contains(@class,"focus-ring")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@id=//button[@aria-expanded]/@aria-controls]')->length)->toBe(1)
        ->and($html)->toContain('open: false')
        ->and($open)->toContain('open: true');
});

test('a mature family is drawn like any other: whether it is offered is the caller\'s decision', function () {
    Genre::factory()->mature()->create(['name' => 'Mature Family', 'slug' => 'mature-family']);

    expect(renderGenrePicker(app(TaxonomyFacets::class)->genres(false)))->not->toContain('mature-family');
    TaxonomyFacets::flush();
    expect(renderGenrePicker(app(TaxonomyFacets::class)->genres(true)))->toContain('mature-family');
});
