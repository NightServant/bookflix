<?php

namespace App\Catalog\Data;

use Carbon\CarbonImmutable;

final readonly class ProviderRecord
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $provider,
        public string $externalId,
        public array $payload,
        public ?string $sourceUrl,
        public CarbonImmutable $fetchedAt,
    ) {}
}
