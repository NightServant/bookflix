<?php

use App\Discovery\Recommendations\CandidateSource;
use App\Providers\RecommendationServiceProvider;

test('the ten sources are tagged and each has its own key', function () {
    $sources = collect(app()->tagged(RecommendationServiceProvider::SOURCES));

    expect($sources)->each->toBeInstanceOf(CandidateSource::class)
        ->and($sources->map->key()->sort()->values()->all())->toBe([
            'als', 'collaborative', 'content_similar', 'dewey_similar', 'editorial', 'exploration', 'new_releases', 'popular_in_genres', 'semantic_similar', 'trending',
        ]);
});
