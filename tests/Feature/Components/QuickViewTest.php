<?php

use App\Models\Media;

function renderQuickView(string $attributes = ''): string
{
    $media = Media::factory()->novel()->create(['title' => 'Frankenstein', 'slug' => 'frankenstein', 'description' => 'A young scientist builds a creature he cannot control.'])->fresh(['mediaType', 'publicPrimaryDewey.deweyClass']);

    return renderedComponent("<x-quick-view :media=\"\$media\" {$attributes} />", ['media' => $media]);
}

test('the trigger is a visible 44px quick view button that also names the story', function () {
    $html = renderQuickView();
    $trigger = htmlQuery($html, '//*[@data-flux-modal-trigger]//button[contains(@class,"min-h-11")][contains(@class,"focus-ring")]');

    expect($trigger->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@data-flux-modal-trigger]//button/span[normalize-space(text())="Quick view"]/span[contains(@class,"sr-only")][normalize-space()="of Frankenstein"]')->length)->toBe(1)
        ->and(preg_replace('/\s+/', ' ', trim($trigger->item(0)->textContent)))->toBe('Quick view of Frankenstein');
});

test('the dialog holds the story summary and is opened by the trigger of the same name', function () {
    $html = renderQuickView();
    $media = Media::firstWhere('slug', 'frankenstein');
    $name = "quick-view-{$media->id}";

    expect($html)->toContain('modal-show')->toContain($name)
        ->and(htmlQuery($html, "//dialog[@data-modal='{$name}']//h2[normalize-space()='Frankenstein']")->length)->toBe(1)
        ->and(htmlQuery($html, "//dialog[@data-modal='{$name}']//a[@href='".route('stories.show', 'frankenstein')."'][normalize-space()='View story']")->length)->toBe(1)
        ->and($html)->not->toContain('Add to Library')
        ->and($html)->toContain('A young scientist builds a creature he cannot control.');
});

test('the name can be changed', function () {
    $html = renderQuickView('name="peek"');

    expect(htmlQuery($html, '//dialog[@data-modal="peek"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//dialog[@data-modal="peek"]//h2[@id="peek-title"]')->length)->toBe(1);
});

test('the dialog is named by the story title', function () {
    $html = renderQuickView();
    $heading = htmlQuery($html, '//dialog//h2[normalize-space()="Frankenstein"]')->item(0);

    expect($heading->getAttribute('id'))->not->toBeEmpty()
        ->and($html)->toContain("closest('dialog').setAttribute('aria-labelledby', '{$heading->getAttribute('id')}')");
});

test('a visible 44px close button replaces the flux one, and tab wraps inside the dialog', function () {
    $html = renderQuickView();

    expect(htmlQuery($html, '//dialog//*[@data-flux-modal-close]//button[@aria-label="Close quick view"][contains(@class,"size-11")][contains(@class,"focus-ring")][@autofocus]')->length)->toBe(1)
        ->and(htmlQuery($html, '//dialog//button[@aria-label="Close modal"]')->length)->toBe(0)
        ->and(htmlQuery($html, '//dialog//*[@*[name()="x-on:keydown.tab.prevent"]="$focus.wrap().next()"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//dialog//*[@*[name()="x-on:keydown.shift.tab.prevent"]="$focus.wrap().previous()"]')->length)->toBe(1);
});

test('attributes land on the wrapper', function () {
    expect(htmlQuery(renderQuickView('data-test="quick" class="mt-2"'), '//*[@data-test="quick"][contains(@class,"mt-2")]')->length)->toBe(1);
});
