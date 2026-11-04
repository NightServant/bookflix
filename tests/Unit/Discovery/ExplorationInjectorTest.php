<?php

use App\Discovery\Recommendations\ExplorationInjector;
use Random\Engine\Mt19937;
use Random\Randomizer;

test('every tenth slot is replaced by an unused exploration item', function () {
    $ranked = collect(range(1, 20))->map(fn ($i) => ranked($i, 1 - $i * 0.01));
    $explore = collect([ranked(101, .4), ranked(102, .4), ranked(1, .4)]);

    $out = (new ExplorationInjector)->inject($ranked, $explore);

    expect($out)->toHaveCount(20)
        ->and($out[9]->mediaId)->toBe(101)->and($out[19]->mediaId)->toBe(102)
        ->and($out->pluck('mediaId')->unique()->count())->toBe(20);
});

test('short lists and empty exploration pools are left unchanged', function () {
    $short = collect(range(1, 5))->map(fn ($i) => ranked($i, .5));

    expect((new ExplorationInjector)->inject($short, collect([ranked(101, .4)]))->pluck('mediaId')->all())->toBe([1, 2, 3, 4, 5])
        ->and((new ExplorationInjector)->inject(collect(range(1, 12))->map(fn ($i) => ranked($i, .5)), collect())->count())->toBe(12);
});

test('a pool that runs out leaves the later slots alone', function () {
    $out = (new ExplorationInjector)->inject(collect(range(1, 25))->map(fn ($i) => ranked($i, .5)), collect([ranked(101, .4)]));

    expect($out->pluck('mediaId')->all())->toBe([...range(1, 9), 101, ...range(11, 25)]);
});

test('an exploration item already in the list is skipped', function () {
    $out = (new ExplorationInjector)->inject(collect(range(1, 12))->map(fn ($i) => ranked($i, .5)), collect([ranked(11, .4), ranked(101, .4)]));

    expect($out->pluck('mediaId')->all())->toBe([...range(1, 9), 101, 11, 12]);
});

test('a seeded randomizer picks the exploration items in a repeatable order', function () {
    $ranked = collect(range(1, 30))->map(fn ($i) => ranked($i, .5));
    $pool = collect(range(101, 110))->map(fn ($i) => ranked($i, .4));
    $inject = fn (int $seed) => (new ExplorationInjector)->inject($ranked, $pool, 10, new Randomizer(new Mt19937($seed)));

    expect($inject(7)->pluck('mediaId')->all())->toBe($inject(7)->pluck('mediaId')->all())
        ->and($inject(7)->pluck('mediaId')->all())->not->toBe($inject(8)->pluck('mediaId')->all())
        ->and([$inject(7)[9]->mediaId, $inject(7)[19]->mediaId, $inject(7)[29]->mediaId])->each->toBeBetween(101, 110)
        ->and($inject(7)->pluck('mediaId')->unique()->count())->toBe(30);
});
