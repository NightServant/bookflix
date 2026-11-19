<?php

namespace App\Reviews;

use Carbon\CarbonImmutable;

final readonly class OutsideReview
{
    public function __construct(
        public string $externalId,
        public string $excerpt,
        public string $url,
        public ?string $authorName = null,
        public ?int $rating = null,
        public ?CarbonImmutable $publishedAt = null,
    ) {}
}
