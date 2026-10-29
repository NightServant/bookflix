<?php

use App\Models\Media;

function renderQuickView(string $name = 'quick-view-test'): string
{
    $media = Media::factory()->novel()->create(['title' => 'Frankenstein', 'slug' => 'frankenstein'])->fresh(['mediaType', 'publicPrimaryDewey.deweyClass']);

    return renderedComponent("<x-story-previews name=\"{$name}\"><x-story-preview :media=\"\$media\"><a href=\"/trigger\">Trigger</a></x-story-preview></x-story-previews>", ['media' => $media]);
}

test('the trigger is a quick view button, visible on coarse pointers only, 44px high, that also names the story', function () {
    $html = renderQuickView();
    $trigger = htmlQuery($html, '//*[contains(@class,"pointer-coarse:block")]/button[contains(@class,"min-h-11")][contains(@class,"focus-ring")]');

    expect($trigger->length)->toBe(1)
        ->and(htmlQuery($html, '//button/span[normalize-space(text())="Quick view"]/span[contains(@class,"sr-only")][normalize-space()="of Frankenstein"]')->length)->toBe(1)
        ->and(preg_replace('/\s+/', ' ', trim($trigger->item(0)->textContent)))->toBe('Quick view of Frankenstein')
        ->and($html)->toContain("@click=\"quickView(\$el.closest('[data-story-preview]'))\"");
});

test('there is one dialog, of the host\'s name, that the story is cloned into', function () {
    $html = renderQuickView();

    expect(htmlQuery($html, '//dialog')->length)->toBe(1)
        ->and(htmlQuery($html, '//dialog[@data-modal="quick-view-test"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//dialog//*[@x-effect="fill($el, quick, \'quick-view-test-title\')"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//dialog//*[@data-story-summary]')->length)->toBe(0);
});

test('the name can be changed', function () {
    expect(htmlQuery(renderQuickView('peek'), '//dialog[@data-modal="peek"]')->length)->toBe(1);
});

test('the dialog is named by the story title', function () {
    expect(renderQuickView())->toContain("closest('dialog').setAttribute('aria-labelledby', 'quick-view-test-title')");
});

test('a visible 44px close button replaces the flux one, and tab wraps inside the dialog', function () {
    $html = renderQuickView();

    expect(htmlQuery($html, '//dialog//*[@data-flux-modal-close]//button[@aria-label="Close quick view"][contains(@class,"size-11")][contains(@class,"focus-ring")][@autofocus]')->length)->toBe(1)
        ->and(htmlQuery($html, '//dialog//button[@aria-label="Close modal"]')->length)->toBe(0)
        ->and(htmlQuery($html, '//dialog//*[@*[name()="x-on:keydown.tab.prevent"]="$focus.wrap().next()"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//dialog//*[@*[name()="x-on:keydown.shift.tab.prevent"]="$focus.wrap().previous()"]')->length)->toBe(1);
});
