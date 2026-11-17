<?php

namespace App\Catalog\Data;

use App\Enums\ContentFormat;

/**
 * A file a provider says a reader may be offered for one of its records: where it is, what it is, and the license text that goes with it.
 * An offer is only a claim: it becomes an unverified content source and nothing is downloaded or shown until an admin verifies it.
 */
final readonly class ContentFileOffer
{
    public function __construct(
        public string $externalKey,
        public string $url,
        public ContentFormat $format,
        public string $license,
        public ?string $rightsHolder = null,
    ) {}
}
