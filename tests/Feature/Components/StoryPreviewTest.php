<?php

use App\Models\Media;

function renderStoryPreview(Media $media, string $attributes = ''): string
{
    return renderedComponent("<x-story-preview :media=\"\$media\" {$attributes}><a href=\"/trigger\">Trigger</a></x-story-preview>", ['media' => $media]);
}

function frankenstein(array $attributes = []): Media
{
    return Media::factory()->novel()->create([
        'title' => 'Frankenstein',
        'slug' => 'frankenstein',
        'description' => 'A young scientist builds a creature he cannot control.',
        'cover_url' => 'https://covers.test/frankenstein.jpg',
        'publication_year' => 1818,
        ...$attributes,
    ])->fresh(['mediaType', 'publicPrimaryDewey.deweyClass']);
}

test('the trigger slot sits in a popover root and the panel is a hidden, named dialog', function () {
    $html = renderStoryPreview(frankenstein());

    expect(htmlQuery($html, '//*[@*[name()="x-popover"]]//*[@x-ref="trigger"]/a[@href="/trigger"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@*[name()="x-popover:panel"]][@role="dialog"][@aria-label="Preview of Frankenstein"][contains(@style,"display: none")]')->length)->toBe(1);
});

test('the panel is anchored to the trigger and expands with motion', function () {
    $html = renderStoryPreview(frankenstein());

    expect(htmlQuery($html, '//*[@*[name()="x-popover:panel"]][@*[name()="x-anchor.bottom-start.offset.8.fixed"]="$refs.trigger"][@*[name()="x-motion-expand"]="open"]')->length)->toBe(1);
});

test('the panel shows the cover, title, story facts, description and both ctas', function () {
    $html = renderStoryPreview(frankenstein());
    $panel = '//*[@role="dialog"]';

    expect(htmlQuery($html, "{$panel}//img[@src='https://covers.test/frankenstein.jpg'][@alt='']")->length)->toBe(1)
        ->and(htmlQuery($html, "{$panel}//h2[normalize-space()='Frankenstein']")->length)->toBe(1)
        ->and(htmlQuery($html, "{$panel}//ul//li[normalize-space()='Novel']")->length)->toBe(1)
        ->and(htmlQuery($html, "{$panel}//ul//li[normalize-space()='1818']")->length)->toBe(1)
        ->and($html)->toContain('A young scientist builds a creature he cannot control.')
        ->and(htmlQuery($html, "{$panel}//a[@href='".route('stories.show', 'frankenstein')."'][@data-flux-button][normalize-space()='Start reading']")->length)->toBe(1)
        ->and(htmlQuery($html, "{$panel}//button[@data-flux-button][normalize-space()='Add to Library']")->length)->toBe(1);
});

test('add to library announces the media id for the library to pick up', function () {
    $media = frankenstein();

    expect(renderStoryPreview($media))->toContain("\$dispatch('library-add', { id: {$media->id} })");
});

test('without a cover or a description the panel falls back to a placeholder and leaves the blurb out', function () {
    $html = renderStoryPreview(frankenstein(['cover_url' => null, 'description' => null]));

    expect(htmlQuery($html, '//*[@role="dialog"]//img')->length)->toBe(0)
        ->and(htmlQuery($html, '//*[@role="dialog"]//*[@data-cover-fallback]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@role="dialog"]//p[contains(@class,"line-clamp")]')->length)->toBe(0);
});

test('attributes land on the wrapper', function () {
    expect(htmlQuery(renderStoryPreview(frankenstein(), 'data-test="preview" class="w-fit"'), '//*[@data-test="preview"][@x-data="storyPreview"][contains(@class,"w-fit")]')->length)->toBe(1);
});
