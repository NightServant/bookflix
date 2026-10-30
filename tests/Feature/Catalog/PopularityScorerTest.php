<?php

use App\Catalog\Popularity\PopularityScorer;
use App\Models\ExternalSource;
use App\Models\Media;
use Illuminate\Support\Facades\DB;

function metric(Media $media, ExternalSource $source, string $metric, float $value): void
{
    DB::table('media_metrics')->insert(['media_id' => $media->id, 'external_source_id' => $source->id, 'metric' => $metric, 'value' => $value, 'observed_at' => now()]);
}

test('a media with stronger signals scores higher and one without metrics stays at zero', function () {
    $source = ExternalSource::factory()->create(['key' => 'anilist']);
    [$low, $mid, $high, $none] = Media::factory()->count(4)->create();

    foreach ([[$low, 10, 5], [$mid, 1000, 50], [$high, 100000, 9000]] as [$media, $popularity, $ratings]) {
        metric($media, $source, 'popularity', $popularity);
        metric($media, $source, 'ratings_count', $ratings);
    }

    expect(app(PopularityScorer::class)->run())->toBe(3);

    $scores = Media::pluck('popularity_score', 'id');

    expect($scores[$high->id])->toBe(1.0)
        ->and($scores[$high->id])->toBeGreaterThan($scores[$mid->id])
        ->and($scores[$mid->id])->toBeGreaterThan($scores[$low->id])
        ->and($scores[$low->id])->toBe(0.0)
        ->and($scores[$none->id])->toBe(0.0);
});

test('weights decide how much each metric counts', function () {
    $source = ExternalSource::factory()->create(['key' => 'anilist']);
    [$popular, $rated] = Media::factory()->count(2)->create();
    metric($popular, $source, 'popularity', 100);
    metric($popular, $source, 'ratings_count', 1);
    metric($rated, $source, 'popularity', 1);
    metric($rated, $source, 'ratings_count', 100);

    config(['bookflix.popularity.weights' => ['popularity' => 3, 'ratings_count' => 1]]);
    app(PopularityScorer::class)->run();

    expect($popular->fresh()->popularity_score)->toBe(0.75)->and($rated->fresh()->popularity_score)->toBe(0.25);
});

test('the readers source carries no popularity weight: its metrics move nobody', function () {
    $anilist = ExternalSource::factory()->create(['key' => 'anilist']);
    $readers = ExternalSource::factory()->create(['key' => 'bookflix']);
    [$low, $high, $readersOnly] = Media::factory()->count(3)->create();

    foreach ([[$low, 10], [$high, 1000]] as [$media, $ratings]) {
        metric($media, $anilist, 'ratings_count', $ratings);
    }

    // The readers' rows would lift $low to the top of their own ranking, and give $readersOnly a score, if they were weighed.
    foreach ([[$low, 90], [$high, 5], [$readersOnly, 40]] as [$media, $ratings]) {
        metric($media, $readers, 'ratings_count', $ratings);
        metric($media, $readers, 'average_rating', 4.5);
    }

    expect(app(PopularityScorer::class)->run())->toBe(2);

    $scores = Media::pluck('popularity_score', 'id');

    expect($scores[$high->id])->toBe(1.0)->and($scores[$low->id])->toBe(0.0)->and($scores[$readersOnly->id])->toBe(0.0);
});

test('trending orders by popularity score, then ratings count', function () {
    $a = Media::factory()->create(['popularity_score' => 0.2, 'ratings_count' => 900]);
    $b = Media::factory()->create(['popularity_score' => 0.9, 'ratings_count' => 1]);
    $c = Media::factory()->create(['popularity_score' => 0.2, 'ratings_count' => 950]);

    expect(Media::trending()->pluck('id')->all())->toBe([$b->id, $c->id, $a->id]);
});

test('the command scores every media with metrics', function () {
    $this->artisan('bookflix:score-popularity')->expectsOutput('Scored 0 media.')->assertSuccessful();
});
