<?php

use App\Enums\CreatorRole;
use App\Enums\MediaFamily;
use App\Enums\ReadingMode;
use App\Models\Creator;
use App\Models\ExternalSource;
use App\Models\Media;
use App\Models\MediaExternalId;
use App\Models\MediaType;
use App\Models\Series;
use Database\Seeders\MediaTypeSeeder;
use Illuminate\Database\QueryException;

test('media types are seeded with family and reading mode', function () {
    $this->seed(MediaTypeSeeder::class);

    expect(MediaType::count())->toBe(10)
        ->and(MediaType::firstWhere('slug', 'manga')->family)->toBe(MediaFamily::SequentialArt)
        ->and(MediaType::firstWhere('slug', 'manga')->reading_mode)->toBe(ReadingMode::Paged)
        ->and(MediaType::firstWhere('slug', 'webcomic')->reading_mode)->toBe(ReadingMode::VerticalScroll)
        ->and(MediaType::firstWhere('slug', 'visual-novel')->family)->toBe(MediaFamily::Interactive)
        ->and(MediaType::firstWhere('slug', 'novel')->reading_mode)->toBe(ReadingMode::Reflowable);
});

test('media relations and casts work', function () {
    $media = Media::factory()->manga()->hasAttached(Creator::factory(), ['role' => 'author', 'position' => 0])->create(['alternative_titles' => ['進撃の巨人']]);

    expect($media->alternative_titles)->toBe(['進撃の巨人'])
        ->and($media->creators->first()->pivot->role)->toBe(CreatorRole::Author)
        ->and($media->mediaType->slug)->toBe('manga');
});

test('media slugs are unique', function () {
    $a = Media::factory()->create(['title' => 'Berserk', 'slug' => null]);
    $b = Media::factory()->create(['title' => 'Berserk', 'slug' => null]);

    expect($a->slug)->not->toBe($b->slug);
});

test('a series position can be fractional', function () {
    $series = Series::factory()->create();
    $m = Media::factory()->create();
    $series->media()->attach($m, ['position' => 1.5]);

    expect((float) $series->media()->first()->pivot->position)->toBe(1.5);
});

test('external id pair is unique per source', function () {
    $source = ExternalSource::factory()->create();
    MediaExternalId::factory()->create(['external_source_id' => $source->id, 'external_id' => 'X1']);

    expect(fn () => MediaExternalId::factory()->create(['external_source_id' => $source->id, 'external_id' => 'X1']))
        ->toThrow(QueryException::class);
});
