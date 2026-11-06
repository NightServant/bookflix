<?php

use App\Discovery\Recommendations\Diversifier;

test('mmr keeps at most four fantasy items in the top ten for a broad-interest reader (spec 31)', function () {
    $items = collect(range(1, 20))->map(fn ($i) => ranked($i, 0.9 - $i * 0.01))      // 20 fantasy, high scores
        ->merge(collect(range(21, 32))->map(fn ($i) => ranked($i, 0.5 - ($i - 21) * 0.01)));   // 12 others over 6 genres
    $genres = fn (int $id) => $id <= 20 ? ['fantasy'] : ['g'.(($id - 21) % 6)];
    $sim = fn (int $a, int $b) => count(array_intersect($genres($a), $genres($b))) / count(array_unique([...$genres($a), ...$genres($b)]));

    $top = (new Diversifier)->rerank($items, 0.7, 10, $sim);

    expect($top)->toHaveCount(10)->and($top->filter(fn ($r) => $r->mediaId <= 20)->count())->toBeLessThanOrEqual(4);
});

test('lambda 1 reduces to plain score order and the limit is respected', function () {
    $items = collect(range(1, 8))->map(fn ($i) => ranked($i, 1 - $i * 0.1));

    expect((new Diversifier)->rerank($items, 1.0, 5, fn () => 1.0)->pluck('mediaId')->all())->toBe([1, 2, 3, 4, 5]);
});

test('the first pick is always the top scored item and fewer items than the limit are all returned', function () {
    expect((new Diversifier)->rerank(collect([ranked(1, .9), ranked(2, .8)]), 0.7, 20, fn () => 0.0)->pluck('mediaId')->all())->toBe([1, 2])
        ->and((new Diversifier)->rerank(collect([ranked(2, .8), ranked(1, .9)]), 0.7, 20, fn () => 1.0)->first()->mediaId)->toBe(1)
        ->and((new Diversifier)->rerank(collect(), 0.7, 20, fn () => 0.0))->toBeEmpty();
});

test('equal scores keep media id order, and a limit below one returns nothing', function () {
    $items = collect([ranked(3, .5), ranked(1, .5), ranked(2, .5)]);

    expect((new Diversifier)->rerank($items, 0.7, 3, fn () => 0.0)->pluck('mediaId')->all())->toBe([1, 2, 3])
        ->and((new Diversifier)->rerank($items, 0.7, 0, fn () => 0.0))->toBeEmpty();
});
