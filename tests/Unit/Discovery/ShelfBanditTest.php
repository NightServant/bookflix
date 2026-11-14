<?php

use App\Discovery\Recommendations\ShelfBandit;
use Random\Engine\Mt19937;
use Random\Randomizer;

const DEFAULT_ORDER = ['continue-reading', 'recommended', 'because-you-read', 'new-releases', 'similar-to', 'trending', 'popular-in-genres', 'discover-new', 'collection-1', 'explore-by-subject'];

function seeded(int $seed): Randomizer
{
    return new Randomizer(new Mt19937($seed));
}

test('with a seeded randomizer the higher click rate shelf is first in a clear majority of 200 trials', function () {
    $stats = ['similar-to' => [5000, 250], 'popular-in-genres' => [5000, 1000], 'discover-new' => [5000, 500]];
    $wins = 0;

    foreach (range(1, 200) as $seed) {
        $order = (new ShelfBandit)->order(DEFAULT_ORDER, $stats, seeded($seed));
        $wins += array_values(array_filter($order, fn ($key) => in_array($key, ShelfBandit::REORDERABLE, true)))[0] === 'popular-in-genres' ? 1 : 0;
    }

    expect($wins)->toBeGreaterThan(160);
});

test('a shelf below 1000 impressions keeps its place whatever its rate', function () {
    $stats = ['similar-to' => [999, 999], 'popular-in-genres' => [5000, 10], 'discover-new' => [5000, 4000]];

    foreach (range(1, 50) as $seed) {
        $order = (new ShelfBandit)->order(DEFAULT_ORDER, $stats, seeded($seed));

        expect(array_search('similar-to', $order))->toBe(4)
            ->and(array_search('discover-new', $order))->toBe(6)
            ->and(array_search('popular-in-genres', $order))->toBe(7);
    }
});

test('fixed shelves never move, and nothing is dropped or duplicated', function () {
    $stats = collect(DEFAULT_ORDER)->mapWithKeys(fn ($key, $i) => [$key => [10_000, $i % 2 ? 9_999 : 0]])->all();

    foreach (range(1, 50) as $seed) {
        $order = (new ShelfBandit)->order(DEFAULT_ORDER, $stats, seeded($seed));

        foreach (DEFAULT_ORDER as $position => $key) {
            if (! in_array($key, ShelfBandit::REORDERABLE, true)) {
                expect($order[$position])->toBe($key);
            }
        }
        expect($order)->toHaveCount(count(DEFAULT_ORDER))->and(collect($order)->sort()->values()->all())->toBe(collect(DEFAULT_ORDER)->sort()->values()->all());
    }
});

test('the same seed gives the same order', function () {
    $stats = ['similar-to' => [2000, 100], 'popular-in-genres' => [2000, 110], 'discover-new' => [2000, 105]];

    expect((new ShelfBandit)->order(DEFAULT_ORDER, $stats, seeded(7)))->toBe((new ShelfBandit)->order(DEFAULT_ORDER, $stats, seeded(7)));
});

test('zero, missing, negative and impossible stats neither divide by zero nor throw', function () {
    $bandit = new ShelfBandit;

    expect($bandit->order(DEFAULT_ORDER, [], seeded(1)))->toBe(DEFAULT_ORDER)
        ->and($bandit->order(DEFAULT_ORDER, ['similar-to' => [0, 0], 'discover-new' => [0, 5]], seeded(1)))->toBe(DEFAULT_ORDER)
        ->and($bandit->order([], ['similar-to' => [5000, 1]], seeded(1)))->toBe([])
        ->and($bandit->order(DEFAULT_ORDER, ['similar-to' => [2000, 9000], 'popular-in-genres' => [-5, -1], 'discover-new' => [2000, 0]], seeded(1)))->toHaveCount(10)
        ->and($bandit->order(DEFAULT_ORDER, ['similar-to' => [PHP_INT_MAX, PHP_INT_MAX], 'discover-new' => [PHP_INT_MAX, 0]], seeded(1)))->toHaveCount(10);
});

test('fewer than two sampled shelves leaves the order alone', function () {
    expect((new ShelfBandit)->order(DEFAULT_ORDER, ['discover-new' => [9000, 9000]], seeded(1)))->toBe(DEFAULT_ORDER);
});
