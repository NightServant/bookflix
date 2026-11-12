<?php

namespace App\Discovery\Recommendations;

use Random\Randomizer;

/**
 * Orders the reorderable shelves of Home by Thompson sampling: each draws a click-through rate from Beta(1 + clicks, 1 + impressions - clicks)
 * and the shelves go in the order of their draws, into the places those shelves held. A shelf with fewer than `MIN_IMPRESSIONS` impressions
 * has no evidence worth sampling and keeps its place, and every other shelf (Continue Reading, Recommended For You and the rest) is never moved.
 * Pure: the stats come from the hourly cache, the randomness from the caller, and a seeded `Randomizer` gives the same order every time.
 */
class ShelfBandit
{
    /** Where `bookflix:refresh-shelf-stats` writes the stats (shelf key => [impressions, clicks]). */
    public const CACHE_KEY = 'recommendations:shelf-stats';

    /** The shelves whose place may change (P8-R8). Collection shelves and staff picks are signed design decisions and stay where they are. */
    public const REORDERABLE = ['similar-to', 'popular-in-genres', 'discover-new'];

    /** The impressions a shelf needs before it is sampled. */
    public const MIN_IMPRESSIONS = 1000;

    /**
     * @param  array<int, string>  $shelfKeys  in the default order
     * @param  array<string, array{0?: int, 1?: int}>  $stats  shelf key => [impressions, clicks]; a missing shelf has none
     * @return array<int, string> the same keys, as a list
     */
    public function order(array $shelfKeys, array $stats, Randomizer $random): array
    {
        $keys = array_values($shelfKeys);
        $slots = [];

        foreach ($keys as $position => $key) {
            if (in_array($key, self::REORDERABLE, true) && $this->counts($stats, $key)[0] >= self::MIN_IMPRESSIONS) {
                $slots[$position] = $key;
            }
        }

        if (count($slots) < 2) {
            return $keys;
        }

        $drawn = [];

        foreach ($slots as $position => $key) {
            [$impressions, $clicks] = $this->counts($stats, $key);
            $drawn[$position] = $this->beta(1.0 + $clicks, 1.0 + ($impressions - $clicks), $random);
        }

        // Best draw first; a tie keeps the default order.
        $positions = array_keys($slots);
        usort($positions, fn (int $a, int $b): int => $drawn[$b] <=> $drawn[$a] ?: $a <=> $b);

        foreach (array_keys($slots) as $index => $slot) {
            $keys[$slot] = $slots[$positions[$index]];
        }

        return $keys;
    }

    /**
     * @param  array<string, array{0?: int, 1?: int}>  $stats
     * @return array{0: int, 1: int} impressions and clicks, never negative and clicks never above impressions
     */
    private function counts(array $stats, string $key): array
    {
        $impressions = max(0, (int) ($stats[$key][0] ?? 0));

        return [$impressions, min($impressions, max(0, (int) ($stats[$key][1] ?? 0)))];
    }

    /** A Beta(a, b) draw, from two gamma draws. */
    private function beta(float $a, float $b, Randomizer $random): float
    {
        $x = $this->gamma($a, $random);
        $y = $this->gamma($b, $random);

        return $x + $y > 0 ? $x / ($x + $y) : 0.5;
    }

    /** A Gamma(shape, 1) draw for shape >= 1 (Marsaglia and Tsang), which is all that 1 + a count can be. */
    private function gamma(float $shape, Randomizer $random): float
    {
        // Far past any real count the draw is the normal it converges to, and the exact method loses its precision.
        if ($shape > 1e6) {
            return max(0.0, $shape + sqrt($shape) * $this->normal($random));
        }

        $d = $shape - 1 / 3;
        $c = 1 / sqrt(9 * $d);

        while (true) {
            do {
                $x = $this->normal($random);
                $v = (1 + $c * $x) ** 3;
            } while ($v <= 0);

            $u = $this->uniform($random);

            if (log($u) < 0.5 * $x * $x + $d - $d * $v + $d * log($v)) {
                return $d * $v;
            }
        }
    }

    /** A standard normal draw (Box-Muller). */
    private function normal(Randomizer $random): float
    {
        return sqrt(-2 * log($this->uniform($random))) * cos(2 * M_PI * $this->uniform($random));
    }

    /** A draw from (0, 1], so a logarithm of it is always finite. */
    private function uniform(Randomizer $random): float
    {
        return 1.0 - $random->getFloat(0.0, 1.0);
    }
}
