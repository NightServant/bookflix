<?php

namespace App\Reviews;

final readonly class OutsideRating
{
    public function __construct(
        public int $score,
        public ?int $votes = null,
        public ?string $url = null,
    ) {}
}
