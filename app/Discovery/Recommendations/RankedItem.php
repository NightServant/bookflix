<?php

namespace App\Discovery\Recommendations;

/**
 * A candidate after hybrid scoring: its score in [0, 1], the eight features behind it (each in [0, 1]) and the explanation to show.
 */
final readonly class RankedItem
{
    /**
     * @param  array<string, float>  $features
     */
    public function __construct(
        public int $mediaId,
        public float $score,
        public array $features,
        public Explanation $explanation,
    ) {}
}
