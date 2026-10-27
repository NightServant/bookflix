<?php

use App\Discovery\Search\SearchTextBuilder;
use App\Models\Media;
use App\Models\User;
use Livewire\Livewire;

/**
 * Titles are searchable once their search text is built, as ingestion does.
 */
function searchable(string ...$titles): void
{
    foreach ($titles as $title) {
        $media = Media::factory()->create(['title' => $title]);
        $media->update(['search_text' => app(SearchTextBuilder::class)->build($media)]);
    }
}

function renderPalette(): string
{
    return renderedComponent('<x-command-palette />');
}

test('the palette is a trigger and a modal dialog named by a title and a description', function () {
    $html = renderPalette();

    expect(htmlQuery($html, '//button[@aria-haspopup="dialog"][contains(normalize-space(),"Search")][contains(@class,"min-h-11")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@x-dialog]//h2[@*[name()="x-dialog:title"]][contains(@class,"sr-only")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@x-dialog]//p[@*[name()="x-dialog:description"]][contains(@class,"sr-only")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@x-dialog][@style="display: none"]')->length)->toBe(1);
});

test('it opens on command-k and control-k from anywhere on the page', function () {
    $html = renderPalette();

    expect(htmlQuery($html, '//*[@*[name()="x-on:keydown.window.prevent.meta.k"]="open = true"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@*[name()="x-on:keydown.window.prevent.ctrl.k"]="open = true"]')->length)->toBe(1);
});

test('a visible 44px close button has an accessible name', function () {
    expect(htmlQuery(renderPalette(), '//button[@aria-label="Close search"][contains(@class,"size-11")][contains(@class,"focus-ring")]')->length)->toBe(1);
});

test('the dialog holds the livewire search results', function () {
    $html = renderPalette();

    expect(htmlQuery($html, '//*[@x-dialog]//*[@*[name()="wire:id"]]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@x-dialog]//input[@*[name()="x-combobox:input"]]')->length)->toBe(1);
});

test('typing a query lists up to 8 matching stories, each a link to its story page', function () {
    searchable(...array_map(fn (int $number) => "Dune Chronicle {$number}", range(1, 10)));
    searchable('Berserk');

    $results = Livewire::actingAs(User::factory()->create())->test('command-palette-results')->set('query', 'Dune');

    expect($results->html())->toContain('Dune Chronicle');

    $html = $results->html();
    $links = htmlQuery($html, '//a[@*[name()="x-combobox:option"]]');

    expect($links->length)->toBe(8)
        ->and($html)->not->toContain('Berserk')
        ->and($links->item(0)->getAttribute('href'))->toStartWith(url('/stories/'));
});

test('a result links to route stories.show with the story slug and carries its type', function () {
    searchable('Berserk');
    $media = Media::firstWhere('title', 'Berserk');

    $html = Livewire::actingAs(User::factory()->create())->test('command-palette-results')->set('query', 'Berserk')->html();

    expect(htmlQuery($html, '//a[@href="'.route('stories.show', $media->slug).'"][@value="'.route('stories.show', $media->slug).'"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//a[contains(.,"Berserk")]')->item(0)->textContent)->toContain($media->mediaType->name);
});

test('results eager-load the media type so lazy loading never fires', function () {
    searchable('Berserk');

    $results = Livewire::actingAs(User::factory()->create())->test('command-palette-results')->set('query', 'Berserk');

    expect($results->get('results')->first()->relationLoaded('mediaType'))->toBeTrue();
});

test('an empty or unmatched query shows no options, and only an unmatched one says so', function () {
    searchable('Berserk');

    $blank = Livewire::actingAs(User::factory()->create())->test('command-palette-results');
    $missing = Livewire::actingAs(User::factory()->create())->test('command-palette-results')->set('query', 'zzzzqx');

    expect(htmlQuery($blank->html(), '//a[@*[name()="x-combobox:option"]]')->length)->toBe(0)
        ->and($blank->html())->not->toContain('No stories match')
        ->and(htmlQuery($missing->html(), '//a[@*[name()="x-combobox:option"]]')->length)->toBe(0)
        ->and($missing->html())->toContain('No stories match');
});

test('the search results are announced to screen readers', function () {
    searchable('Berserk');

    $html = Livewire::actingAs(User::factory()->create())->test('command-palette-results')->set('query', 'Berserk')->html();

    expect(htmlQuery($html, '//p[@role="status"][contains(@class,"sr-only")][contains(.,"1 result")]')->length)->toBe(1);
});

test('the card is one piece: icon, borderless field and close button share a header row above a hairline', function () {
    $html = renderPalette();

    expect(htmlQuery($html, '//*[@data-palette-panel][contains(@class,"max-w-2xl")][contains(@class,"overflow-hidden")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@data-palette-panel]//div[contains(@class,"border-b")][.//svg][.//input[@*[name()="x-combobox:input"]][contains(@class,"outline-none")]][.//button[@aria-label="Close search"]]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@data-palette-panel]//input[@data-flux-control]')->length)->toBe(0);
});

test('a blank palette hints at what to type', function () {
    expect(htmlQuery(renderPalette(), '//*[@data-palette-panel]//p[contains(.,"Type a title, an author or a subject.")]')->length)->toBe(1);
});
