<?php

use App\Catalog\MediaRatings;
use App\Models\Media;

/**
 * Fold the media's sources into its rating columns and read them back.
 *
 * @return array{average: float|null, count: int}
 */
function blended(Media $media, bool $clearEmpty = false): array
{
    MediaRatings::blend($media, $clearEmpty);
    $media = $media->fresh();

    return ['average' => $media->average_rating === null ? null : (float) $media->average_rating, 'count' => $media->ratings_count];
}

test('the counts add up and the average is weighted by each sources count', function () {
    $media = Media::factory()->create();
    providerMetrics($media, 'anilist', ['average_rating' => 4.0, 'ratings_count' => 100]);
    providerMetrics($media, 'mangadex', ['average_rating' => 3.0, 'ratings_count' => 300]);

    expect(blended($media))->toBe(['average' => 3.25, 'count' => 400]);
});

test('a source without a count weighs one, and one without an average adds only its count', function () {
    $media = Media::factory()->create();
    providerMetrics($media, 'anilist', ['average_rating' => 5.0]);
    providerMetrics($media, 'mangadex', ['average_rating' => 4.0, 'ratings_count' => 3]);
    providerMetrics($media, 'openlibrary', ['ratings_count' => 6]);

    // (5.0 x 1 + 4.0 x 3) / 4; the counts are 3 + 6.
    expect(blended($media))->toBe(['average' => 4.25, 'count' => 9]);
});

test('a column an admin locked is left alone', function () {
    $media = Media::factory()->create(['average_rating' => '2.50', 'ratings_count' => 7, 'locked_fields' => ['average_rating']]);
    providerMetrics($media, 'anilist', ['average_rating' => 4.0, 'ratings_count' => 10]);

    expect(blended($media))->toBe(['average' => 2.5, 'count' => 10]);

    $media->update(['locked_fields' => ['ratings_count']]);

    expect(blended($media->fresh()))->toBe(['average' => 4.0, 'count' => 10]);
});

test('with no rating on record the columns keep what they had, unless the caller asks them to follow', function () {
    $media = Media::factory()->create(['average_rating' => '3.10', 'ratings_count' => 12]);
    providerMetrics($media, 'wikipedia', ['pageviews_30d' => 900]);

    expect(blended($media))->toBe(['average' => 3.1, 'count' => 12])
        ->and(blended($media, clearEmpty: true))->toBe(['average' => null, 'count' => 0]);
});

test('clearing follows the locks too', function () {
    $media = Media::factory()->create(['average_rating' => '3.10', 'ratings_count' => 12, 'locked_fields' => ['average_rating']]);

    expect(blended($media, clearEmpty: true))->toBe(['average' => 3.1, 'count' => 0]);
});

test('the average is rounded to two decimals', function () {
    $media = Media::factory()->create();
    providerMetrics($media, 'anilist', ['average_rating' => 4.0, 'ratings_count' => 1]);
    providerMetrics($media, 'mangadex', ['average_rating' => 3.0, 'ratings_count' => 2]);

    expect(blended($media)['average'])->toBe(3.33);
});

test('another story is never read or written', function () {
    [$media, $other] = Media::factory()->count(2)->create();
    providerMetrics($media, 'anilist', ['average_rating' => 4.0, 'ratings_count' => 10]);
    providerMetrics($other, 'anilist', ['average_rating' => 1.0, 'ratings_count' => 90]);

    expect(blended($media))->toBe(['average' => 4.0, 'count' => 10])
        ->and($other->fresh()->ratings_count)->toBe(0);
});
