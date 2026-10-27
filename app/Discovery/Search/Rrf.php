<?php

namespace App\Discovery\Search;

class Rrf
{
    /**
     * Reciprocal rank fusion: an id scores 1 / (k + rank) in every list that holds it, and the scores add up.
     *
     * @param  list<list<int>>  $rankedLists  best first
     * @return array<int, float> id => score, highest first; equal scores by id ascending, so the order never depends on how a leg happened to list ties
     */
    public static function fuse(array $rankedLists, int $k = 60): array
    {
        $scores = [];

        foreach ($rankedLists as $ids) {
            foreach ($ids as $index => $id) {
                $scores[$id] = ($scores[$id] ?? 0.0) + 1 / ($k + $index + 1);
            }
        }

        uksort($scores, fn (int $a, int $b): int => [$scores[$b], $a] <=> [$scores[$a], $b]);

        return $scores;
    }
}
