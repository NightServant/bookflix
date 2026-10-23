<?php

namespace App\Catalog\Ingestion;

use App\Catalog\Data\NormalizedCreator;
use App\Catalog\Data\NormalizedMedia;
use App\Catalog\Data\ProviderRecord;
use App\Models\MediaType;
use Illuminate\Support\Str;

class RecordValidator
{
    /**
     * The longest title, slug, creator or publisher name the string(255) columns take.
     */
    private const MAX_LENGTH = 255;

    /**
     * Whether a record is fit to store: a title, an external id, a media type we know, not adult (Q15),
     * and names that fit their columns, so one oversized value skips the record instead of failing the import.
     */
    public function validate(ProviderRecord $record, NormalizedMedia $media): bool
    {
        return trim($media->title) !== ''
            && trim($record->externalId) !== ''
            && ! $media->isAdult
            && $this->fitsColumns($media)
            && MediaType::where('slug', $media->mediaTypeSlug)->exists();
    }

    private function fitsColumns(NormalizedMedia $media): bool
    {
        $names = [$media->title, ...array_map(fn (NormalizedCreator $creator): string => $creator->name, $media->creators), ...$media->publishers];

        foreach ($names as $name) {
            if (mb_strlen($name) > self::MAX_LENGTH || mb_strlen(Str::slug($name)) > self::MAX_LENGTH) {
                return false;
            }
        }

        return true;
    }
}
