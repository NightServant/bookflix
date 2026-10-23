<?php

namespace App\Catalog\Ingestion;

use App\Catalog\Data\NormalizedMedia;
use App\Catalog\Data\ProviderRecord;
use App\Models\MediaType;

class RecordValidator
{
    /**
     * Whether a record is fit to store: a title, an external id, a media type we know, and not adult (Q15).
     */
    public function validate(ProviderRecord $record, NormalizedMedia $media): bool
    {
        return trim($media->title) !== ''
            && trim($record->externalId) !== ''
            && ! $media->isAdult
            && MediaType::where('slug', $media->mediaTypeSlug)->exists();
    }
}
