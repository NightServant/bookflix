<?php

use App\Discovery\Recommendations\RankingWeights;

test('fromArray reads the eight weights and round trips', function () {
    $weights = RankingWeights::fromArray(['content' => 0.3, 'collaborative' => 0.25, 'dewey' => 0.1, 'popularity' => 0.1, 'freshness' => 0.05, 'preference' => 0.15, 'novelty' => 0.05, 'context' => 0.0]);

    expect($weights->content)->toBe(0.3)
        ->and($weights->toArray())->toBe(['content' => 0.3, 'collaborative' => 0.25, 'dewey' => 0.1, 'popularity' => 0.1, 'freshness' => 0.05, 'preference' => 0.15, 'novelty' => 0.05, 'context' => 0.0]);
});

test('a missing, negative, non numeric or non finite weight counts as zero', function () {
    $weights = RankingWeights::fromArray(['content' => 1, 'collaborative' => -0.5, 'dewey' => 'lots', 'popularity' => NAN, 'freshness' => INF, 'preference' => '0.2']);

    expect($weights->content)->toBe(1.0)
        ->and($weights->collaborative)->toBe(0.0)
        ->and($weights->dewey)->toBe(0.0)
        ->and($weights->popularity)->toBe(0.0)
        ->and($weights->freshness)->toBe(0.0)
        ->and($weights->preference)->toBe(0.2)
        ->and($weights->novelty)->toBe(0.0)
        ->and($weights->context)->toBe(0.0);
});
