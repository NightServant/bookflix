<?php

namespace App\Reviews;

use App\Catalog\Providers\MissingProviderCredentials;
use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\RateLimitedByProvider;

/**
 * A provider that can hand back the reviews and rating its own official API holds for a media it already knows (P10-R20).
 * Mapping drift yields fewer reviews, never an exception; a failed or malformed answer is a ProviderUnavailable, so the stored rows stay.
 */
interface OffersReviews
{
    public function key(): string;

    /**
     * @throws RateLimitedByProvider
     * @throws ProviderUnavailable
     * @throws MissingProviderCredentials
     */
    public function outsideReviews(string $externalId): OutsideReviewData;
}
