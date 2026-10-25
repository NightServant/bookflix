<?php

use App\Discovery\Search\Rrf;

test('rrf rewards items ranked high in several lists', function () {
    $fused = Rrf::fuse([[1, 2, 3], [3, 1, 4]], k: 60);

    expect(array_key_first($fused))->toBe(1)
        ->and($fused[1])->toEqualWithDelta(1 / 61 + 1 / 62, 1e-9)
        ->and(array_keys($fused))->toBe([1, 3, 2, 4]);
});

test('rrf with no lists is empty', function () {
    expect(Rrf::fuse([]))->toBe([]);
});
