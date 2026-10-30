<?php

use App\Enums\AgeRating;
use App\Models\Media;
use App\Models\MediaType;
use Database\Seeders\DeweySeeder;
use Database\Seeders\MediaTypeSeeder;
use Illuminate\Support\Facades\DB;

function renderStoryInfo(Media $media): string
{
    return renderedComponent('<x-story-info :media="$media" />', ['media' => $media]);
}

/**
 * @return list<string>
 */
function infoParts(string $html): array
{
    return collect(htmlQuery($html, '//li'))->map(fn ($li) => trim(preg_replace('/\s+/', ' ', $li->textContent)))->all();
}

test('facts come in order: type, year, length, age rating, class code', function () {
    $this->seed(DeweySeeder::class);
    $media = classified(Media::factory()->manga()->create(['publication_year' => 1989, 'page_count' => 224, 'age_rating' => AgeRating::Teen]), '813');

    expect(infoParts(renderStoryInfo($media)))->toBe(['Manga', '1989', '224 pages', 'Teen', 'Class 813']);
});

test('a book that is more than one type names them all', function () {
    $media = Media::factory()->graphicNovel()->create(['publication_year' => 2003, 'page_count' => null, 'chapter_count' => null, 'age_rating' => AgeRating::Unknown]);
    $media->additionalTypes()->attach([
        MediaType::firstOrCreate(['slug' => 'comic-book'], MediaTypeSeeder::TYPES['comic-book'])->id,
        MediaType::firstOrCreate(['slug' => 'nonfiction'], MediaTypeSeeder::TYPES['nonfiction'])->id,
    ]);

    expect(infoParts(renderStoryInfo($media->load('additionalTypes'))))->toBe(['Graphic Novel, Comic Book & Nonfiction', '2003']);
});

test('parts that are missing are left out', function () {
    $media = Media::factory()->novel()->create(['publication_year' => null, 'page_count' => null, 'chapter_count' => 12, 'age_rating' => AgeRating::Unknown]);

    expect(infoParts(renderStoryInfo($media)))->toBe(['Novel', '12 chapters']);
});

test('a single page or chapter is singular', function () {
    expect(infoParts(renderStoryInfo(Media::factory()->create(['page_count' => 1]))))->toContain('1 page')
        ->and(infoParts(renderStoryInfo(Media::factory()->create(['page_count' => null, 'chapter_count' => 1]))))->toContain('1 chapter');
});

test('the class code shows only for a public primary class', function (string $type, string $status, float $confidence, bool $shown) {
    $this->seed(DeweySeeder::class);
    $media = classified(Media::factory()->create(), '813', $type, $status, $confidence);

    expect(str_contains(renderStoryInfo($media), '813'))->toBe($shown);
})->with([
    'accepted primary' => ['primary', 'accepted', 0.5, true],
    'confident pending primary' => ['primary', 'pending', 0.9, true],
    'unsure pending primary' => ['primary', 'pending', 0.4, false],
    'rejected primary' => ['primary', 'rejected', 0.95, false],
    'accepted secondary' => ['secondary', 'accepted', 0.95, false],
]);

test('separators are CSS, never text, so none can be stray', function () {
    $html = renderStoryInfo(Media::factory()->create(['publication_year' => null, 'page_count' => null]));

    expect(strip_tags($html))->not->toContain('·')->and(substr_count($html, '<li'))->toBe(1);
});

test('eager-loaded media renders without any further query', function () {
    $this->seed(DeweySeeder::class);
    classified(Media::factory()->manga()->create(['page_count' => 10]), '813');
    $media = Media::with(['mediaType', 'publicPrimaryDewey.deweyClass'])->first();

    DB::enableQueryLog();
    renderStoryInfo($media);

    expect(DB::getQueryLog())->toBeEmpty();
});
