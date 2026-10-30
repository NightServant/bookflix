<?php

use App\Models\Media;
use App\Models\User;

function renderStoryPreview(Media $media, string $attributes = ''): string
{
    return renderedComponent("<x-story-previews name=\"quick-view-test\"><x-story-preview :media=\"\$media\" {$attributes}><a href=\"/trigger\">Trigger</a></x-story-preview></x-story-previews>", ['media' => $media]);
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

test('a tile holds the trigger, its summary in an inert template and the touch quick view button, and no card of its own', function () {
    $html = renderStoryPreview(frankenstein());
    $tile = '//*[@data-story-preview][@data-preview-label="Preview of Frankenstein"]';

    expect(htmlQuery($html, "{$tile}/div/a[@href='/trigger']")->length)->toBe(1)
        ->and(htmlQuery($html, "{$tile}/template/*[@data-story-summary]")->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@data-story-summary][not(ancestor::template)]')->length)->toBe(0)
        ->and(htmlQuery($html, "{$tile}//*[@role='dialog']")->length)->toBe(0)
        ->and(htmlQuery($html, "{$tile}//div[contains(@class,'hidden')][contains(@class,'pointer-coarse:block')]/button[contains(@class,'min-h-11')][normalize-space()='Quick view of Frankenstein']")->length)->toBe(1);
});

test('the shared card sits after the tiles in a popover root, hidden and anchored to the tile in use', function () {
    $html = renderStoryPreview(frankenstein());

    expect(htmlQuery($html, '//*[@x-data="storyPreviews(\'quick-view-test\')"]//*[@*[name()="x-popover"]]//*[@*[name()="x-popover:panel"]][@role="dialog"][@data-story-card][contains(@style,"display: none")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@data-story-card][@*[name()="x-anchor.bottom-start.offset.8.fixed"]="active?.firstElementChild"][@*[name()="x-motion-expand"]="open"][@*[name()=":aria-label"]="active?.dataset.previewLabel"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@data-story-card]//*[@x-effect="fill($el, active)"]')->length)->toBe(1);
});

test('the summary has the cover, title, story facts, description and the view story cta, and for a guest nothing else', function () {
    $html = renderStoryPreview(frankenstein());
    $summary = '//template/*[@data-story-summary]';

    expect(htmlQuery($html, "{$summary}//img[@src='https://covers.test/frankenstein.jpg'][@alt='']")->length)->toBe(1)
        ->and(htmlQuery($html, "{$summary}//h2[normalize-space()='Frankenstein']")->length)->toBe(1)
        ->and(htmlQuery($html, "{$summary}//ul//li[normalize-space()='Novel']")->length)->toBe(1)
        ->and(htmlQuery($html, "{$summary}//ul//li[normalize-space()='1818']")->length)->toBe(1)
        ->and($html)->toContain('A young scientist builds a creature he cannot control.')
        ->and(htmlQuery($html, "{$summary}//a[@href='".route('stories.show', 'frankenstein')."'][@data-flux-button][normalize-space()='View story']")->length)->toBe(1)
        ->and(htmlQuery($html, "{$summary}//button[@data-flux-button]")->length)->toBe(0)
        ->and($html)->not->toContain('library-add')->not->toContain('Add to Library');
});

test('for a reader the summary adds an icon-only add to library button that announces the media id', function () {
    $this->actingAs(User::factory()->create());
    $media = frankenstein();
    $html = renderStoryPreview($media);

    expect(htmlQuery($html, "//template//button[@data-flux-button][@aria-label='Add to Library'][normalize-space()='']//*[@data-flux-icon]")->length)->toBe(1)
        ->and(htmlQuery($html, "//template//a[@data-flux-button][contains(@class,'flex-1')][normalize-space()='View story']")->length)->toBe(1)
        ->and(htmlQuery($html, "//template//a[@data-flux-button]/parent::div[contains(@class,'flex-col')]")->length)->toBe(0)
        ->and($html)->toContain("\$dispatch('library-add', { id: {$media->id} })");
});

test('the card holds no form: it only exists once scripts have cloned it', function () {
    $this->actingAs(User::factory()->create());

    expect(htmlQuery(renderStoryPreview(frankenstein()), '//template//form')->length)->toBe(0);
});

test('without a cover or a description the summary falls back to a placeholder and leaves the blurb out', function () {
    $html = renderStoryPreview(frankenstein(['cover_url' => null, 'description' => null]));

    expect(htmlQuery($html, '//template//img')->length)->toBe(0)
        ->and(htmlQuery($html, '//template//*[@data-cover-fallback]')->length)->toBe(1)
        ->and(htmlQuery($html, '//template//p[contains(@class,"line-clamp")]')->length)->toBe(0);
});

test('attributes land on the tile', function () {
    expect(htmlQuery(renderStoryPreview(frankenstein(), 'data-test="preview" class="w-fit"'), '//*[@data-test="preview"][@data-story-preview][contains(@class,"w-fit")]')->length)->toBe(1);
});

test('a shelf with previews holds one card and one quick view for all its tiles, and a shelf without holds none', function () {
    $media = Media::factory()->novel()->count(3)->create()->map(fn ($media) => $media->fresh(['mediaType', 'publicPrimaryDewey.deweyClass']));
    $tiles = '@foreach ($media as $item)<li wire:key="m-{{ $item->id }}"><x-story-preview :media="$item"><a href="/t">T</a></x-story-preview></li>@endforeach';

    $with = renderedComponent("<x-shelf title=\"Gothic\" previews=\"quick-view-gothic\">{$tiles}</x-shelf>", ['media' => $media]);
    $without = renderedComponent('<x-shelf title="Gothic"><li>Plain</li></x-shelf>');

    expect(htmlQuery($with, '//*[@data-story-preview]')->length)->toBe(3)
        ->and(htmlQuery($with, '//*[@data-story-card]')->length)->toBe(1)
        ->and(htmlQuery($with, '//dialog[@data-modal="quick-view-gothic"]')->length)->toBe(1)
        ->and(htmlQuery($with, '//dialog')->length)->toBe(1)
        ->and(htmlQuery($with, '//*[@data-story-previews]//section[@aria-labelledby="shelf-gothic"]')->length)->toBe(1)
        ->and(htmlQuery($without, '//*[@data-story-previews] | //dialog | //*[@data-story-card]')->length)->toBe(0)
        ->and(htmlQuery($without, '//section[@aria-labelledby="shelf-gothic"]//li[normalize-space()="Plain"]')->length)->toBe(1);
});
