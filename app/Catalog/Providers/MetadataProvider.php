<?php

namespace App\Catalog\Providers;

use App\Catalog\Data\NormalizedMedia;
use App\Catalog\Data\ProviderPage;
use App\Catalog\Data\ProviderRecord;
use Carbon\CarbonImmutable;

interface MetadataProvider
{
    public function key(): string;

    /**
     * One bounded page of the records changed since the cursor (everything when null). Pass the previous page's
     * `nextPageToken` to continue, null to start; a page with no next token is the last one.
     *
     * @throws RateLimitedByProvider
     * @throws ProviderUnavailable
     */
    public function page(?CarbonImmutable $since, ?string $pageToken): ProviderPage;

    /**
     * @throws RateLimitedByProvider
     * @throws ProviderUnavailable
     */
    public function find(string $externalId): ?ProviderRecord;

    /**
     * Pure mapping, no I/O. Shape drift yields empty values, never an exception.
     */
    public function normalize(ProviderRecord $record): NormalizedMedia;
}
