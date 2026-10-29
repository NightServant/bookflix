<?php

use App\Classification\ClassShelf;
use App\Classification\ClassShelves;
use App\Models\Media;
use Database\Seeders\DeweySeeder;
use Illuminate\Support\Collection;

/**
 * @return Collection<int, ClassShelf>
 */
function oneShelf()
{
    (new DeweySeeder)->run();

    return app(ClassShelves::class)->forMedia(classified(Media::factory()->novel()->count(2)->create(), '813')->load('mediaType'));
}

test('each shelf is a titled row that links to its class page by default', function () {
    $html = renderedComponent('<x-class-shelves :shelves="$shelves" />', ['shelves' => oneShelf()]);

    expect(htmlQuery($html, '//section//h2[normalize-space()="Literature"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//section//a[@href="'.route('explore.show', '800').'"]')->length)->toBeGreaterThan(0);
});

test('a page that lists its own stories can turn the see all links off', function () {
    $html = renderedComponent('<x-class-shelves :shelves="$shelves" :links="false" />', ['shelves' => oneShelf()]);

    expect(htmlQuery($html, '//section//h2[normalize-space()="Literature"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//section//a[contains(@href,"/explore/")]')->length)->toBe(0);
});
