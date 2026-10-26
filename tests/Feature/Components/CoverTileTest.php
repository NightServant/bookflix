<?php

use App\Models\Media;

function renderTile(Media $media, string $attributes = ''): string
{
    return renderedComponent("<x-cover-tile :media=\"\$media\" {$attributes} />", ['media' => $media]);
}

test('a tile is a lazy 2:3 cover linking to the story page', function () {
    $media = Media::factory()->create(['title' => 'Dune', 'slug' => 'dune', 'cover_url' => 'https://covers.test/dune.jpg']);

    $html = renderTile($media);

    expect(htmlQuery($html, '//a[@href="'.route('stories.show', 'dune').'"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//img[@loading="lazy"][@width][@height][@alt][@src="https://covers.test/dune.jpg"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[contains(@class,"aspect-[2/3]")]')->length)->toBe(1)
        ->and($html)->toContain('Dune')
        ->and($html)->toContain('focus-ring');
});

test('sizes set the width and the intrinsic dimensions, and fluid fills the cell', function (string $size, string $width, string $height) {
    $html = renderTile(Media::factory()->create(['cover_url' => 'https://covers.test/a.jpg']), "size=\"{$size}\"");

    expect(htmlQuery($html, "//img[@width='{$width}'][@height='{$height}']")->length)->toBe(1);
})->with([
    'small' => ['S', '112', '168'],
    'medium' => ['M', '160', '240'],
    'large' => ['L', '224', '336'],
]);

test('fluid replaces the fixed width', function () {
    $media = Media::factory()->create();

    expect(renderTile($media, 'fluid'))->toContain('w-full')->not->toContain('w-36')
        ->and(renderTile($media))->toContain('w-36');
});

test('a tile without a cover shows a placeholder, not a broken image', function () {
    $html = renderTile(Media::factory()->create(['cover_url' => null]));

    expect(htmlQuery($html, '//img')->length)->toBe(0)
        ->and(htmlQuery($html, '//svg')->length)->toBeGreaterThan(0);
});

test('the media type badge is optional', function () {
    $media = Media::factory()->manga()->create();

    expect(renderTile($media))->not->toContain('Manga')
        ->and(renderTile($media, 'badge'))->toContain('Manga');
});

test('a progress bar and a reason show only when given', function () {
    $media = Media::factory()->create();

    expect(htmlQuery(renderTile($media), '//*[@role="progressbar"]')->length)->toBe(0)
        ->and(renderTile($media))->not->toContain('Because you read');

    $html = renderTile($media, ':progress="40" reason="Because you read Dune"');

    expect(htmlQuery($html, '//*[@role="progressbar"][@aria-valuenow="40"][@aria-valuemin="0"][@aria-valuemax="100"]')->length)->toBe(1)
        ->and($html)->toContain('Because you read Dune');
});

test('progress is clamped to 0-100', function () {
    $media = Media::factory()->create();

    expect(htmlQuery(renderTile($media, ':progress="250"'), '//*[@role="progressbar"][@aria-valuenow="100"]')->length)->toBe(1)
        ->and(htmlQuery(renderTile($media, ':progress="-5"'), '//*[@role="progressbar"][@aria-valuenow="0"]')->length)->toBe(1);
});

test('the hover scale is motion-safe only', function () {
    $html = renderTile(Media::factory()->create(['cover_url' => 'https://covers.test/a.jpg']));

    expect($html)->toContain('motion-safe:group-hover:scale-105')->not->toMatch('/(?<!motion-safe:)group-hover:scale/');
});
