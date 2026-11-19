<?php

namespace App\Reviews;

/**
 * What one provider offered for one media: at most ten reviews and at most one rating.
 */
final readonly class OutsideReviewData
{
    /**
     * @param  list<OutsideReview>  $reviews
     */
    public function __construct(
        public array $reviews = [],
        public ?OutsideRating $rating = null,
    ) {}
}
