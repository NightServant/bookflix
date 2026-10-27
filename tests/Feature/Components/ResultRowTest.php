<?php

use App\Models\Creator;
use App\Models\Genre;
use App\Models\Media;
use Illuminate\Database\Eloquent\Collection;

/**
 * A fetched, fully eager-loaded media, the way a results page hands it over.
 *
 * @param  array<string, mixed>  $attributes
 */
function resultMedia(array $attributes = []): Media
{
    $media = Media::factory()->novel()->create([
        'title' => 'Dracula',
        'slug' => 'dracula',
        'description' => 'A solicitor travels to Transylvania and meets a count.',
        'cover_url' => 'https://covers.test/dracula.jpg',
        'publication_year' => 1897,
        ...$attributes,
    ]);

    return fetchResults()->firstWhere('id', $media->id);
}

/**
 * @return Collection<int, Media>
 */
function fetchResults(): Collection
{
    return Media::with(['mediaType', 'publicPrimaryDewey.deweyClass', 'creators', 'genres'])->get();
}

function renderResultRow(Media $media, string $attributes = ''): string
{
    return renderedComponent("<x-result-row :media=\"\$media\" {$attributes} />", ['media' => $media]);
}

test('a row is an article with a small lazy 2:3 cover that leads to the story but stays out of the tab order', function () {
    $html = renderResultRow(resultMedia());

    expect(htmlQuery($html, '//article')->length)->toBe(1)
        ->and(htmlQuery($html, '//a[@href="'.route('stories.show', 'dracula').'"][@aria-hidden="true"][@tabindex="-1"]//img[@src="https://covers.test/dracula.jpg"][@alt=""][@loading="lazy"][@width][@height]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[contains(@class,"aspect-[2/3]")]')->length)->toBe(1);
});

test('the title is the one link a screen reader meets, a 44px target with the focus ring', function () {
    $html = renderResultRow(resultMedia());

    expect(htmlQuery($html, '//h3/a[@href="'.route('stories.show', 'dracula').'"][@*[name()="wire:navigate"]][normalize-space()="Dracula"][contains(@class,"focus-ring")][contains(@class,"min-h-11")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//a[not(@aria-hidden)]')->length)->toBe(1);
});

test('a row without a cover shows a placeholder, not a broken image', function () {
    $html = renderResultRow(resultMedia(['cover_url' => null]));

    expect(htmlQuery($html, '//img')->length)->toBe(0)
        ->and(htmlQuery($html, '//*[@data-cover-fallback]')->length)->toBe(1);
});

test('creators are listed once each, in credit order', function () {
    $media = Media::factory()->novel()->create();
    $shelley = Creator::factory()->create(['name' => 'Mary Shelley']);
    $stoker = Creator::factory()->create(['name' => 'Bram Stoker']);
    $media->creators()->attach($stoker, ['role' => 'illustrator', 'position' => 2]);
    $media->creators()->attach($shelley, ['role' => 'author', 'position' => 1]);
    $media->creators()->attach($shelley, ['role' => 'illustrator', 'position' => 3]);

    $html = renderResultRow(fetchResults()->firstWhere('id', $media->id));

    expect(preg_replace('/\s+/', ' ', trim(htmlQuery($html, '//p[@data-creators]')->item(0)->textContent)))->toBe('Mary Shelley, Bram Stoker');
});

test('a row without creators leaves the line out', function () {
    expect(htmlQuery(renderResultRow(resultMedia()), '//p[@data-creators]')->length)->toBe(0);
});

test('the facts line is the shared story info', function () {
    $html = renderResultRow(resultMedia());

    expect(htmlQuery($html, '//article//ul[1]/li[normalize-space()="Novel"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//article//ul[1]/li[normalize-space()="1897"]')->length)->toBe(1);
});

test('the description is clamped to two lines and left out when there is none', function () {
    expect(htmlQuery(renderResultRow(resultMedia()), '//p[contains(@class,"line-clamp-2")][contains(.,"travels to Transylvania")]')->length)->toBe(1)
        ->and(htmlQuery(renderResultRow(resultMedia(['description' => null, 'slug' => 'no-blurb'])), '//p[contains(@class,"line-clamp-2")]')->length)->toBe(0);
});

test('genres show as chips, at most four, and the list is left out without genres', function () {
    $media = Media::factory()->novel()->create();
    $media->genres()->attach(Genre::factory()->count(6)->sequence(...array_map(fn ($name) => ['name' => $name, 'slug' => strtolower($name)], ['Gothic', 'Horror', 'Romance', 'Mystery', 'Drama', 'Satire']))->create());

    $chips = htmlQuery(renderResultRow(fetchResults()->firstWhere('id', $media->id)), '//ul[@aria-label="Genres"]/li[contains(@class,"rounded-full")]');

    expect($chips->length)->toBe(4)
        ->and(htmlQuery(renderResultRow(resultMedia()), '//ul[@aria-label="Genres"]')->length)->toBe(0);
});

test('with the documented eager loads a fetched row never lazy loads', function () {
    $media = Media::factory()->count(3)->novel()->create(['cover_url' => 'https://covers.test/a.jpg']);
    $media->each(fn ($item) => $item->genres()->attach(Genre::factory()->create()));

    $rows = fetchResults()->map(fn ($item) => renderResultRow($item));

    expect($rows)->toHaveCount(3)->each->toContain('<article');
});

test('attributes land on the article', function () {
    expect(htmlQuery(renderResultRow(resultMedia(), 'data-test="row" class="mb-3"'), '//article[@data-test="row"][contains(@class,"mb-3")]')->length)->toBe(1);
});
