<?php

namespace App\Catalog\Providers;

use App\Catalog\Data\NormalizedMedia;
use App\Catalog\Data\ProviderRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\LazyCollection;

interface MetadataProvider
{
    public function key(): string;

    /**
     * @return LazyCollection<int, ProviderRecord>
     *
     * @throws RateLimitedByProvider
     * @throws ProviderUnavailable
     */
    public function changedSince(?CarbonImmutable $since): LazyCollection;

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
