<?php

namespace App\Catalog\Data;

use Carbon\CarbonImmutable;

final readonly class ProviderPage
{
    /**
     * @param  list<ProviderRecord>  $records
     * @param  ?string  $nextPageToken  Opaque to everyone but the provider; null on the last page.
     * @param  ?CarbonImmutable  $maxUpdatedAt  The newest provider-side update among the records, which becomes the next sync cursor.
     */
    public function __construct(
        public array $records,
        public ?string $nextPageToken = null,
        public ?CarbonImmutable $maxUpdatedAt = null,
    ) {}
}
