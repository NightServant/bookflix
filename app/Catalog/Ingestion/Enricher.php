<?php

namespace App\Catalog\Ingestion;

use App\Catalog\Data\NormalizedMedia;
use App\Enums\AgeRating;
use App\Enums\PublicationStatus;
use App\Models\ExternalSource;
use App\Models\Media;

class Enricher
{
    /**
     * Fill a media's own columns from a provider record. The names double as the values admins can lock.
     *
     * Precedence (A9): an admin-locked field is never touched, then what is already stored beats a newer
     * provider's value. The media's primary provider (`primary_external_source_id`, set when the record was created and
     * kept through merges) is the exception: its re-syncs keep the record current, which also stops two providers
     * overwriting each other on every sync. A media without one is claimed by the first provider that touches it.
     *
     * `$mappedRating` is the audience rating the taxonomy mapping found in the record's terms (an ecchi tag implies mature teen);
     * the stricter of it and the provider's own rating is stored.
     */
    public function apply(Media $media, NormalizedMedia $incoming, ExternalSource $source, ?AgeRating $mappedRating = null): void
    {
        $media->primary_external_source_id ??= $source->id;
        $authoritative = $media->primary_external_source_id === $source->id;

        $values = [
            'title' => trim($incoming->title),
            'alternative_titles' => $incoming->alternativeTitles,
            'description' => $incoming->description,
            'cover_url' => $incoming->coverUrl,
            'published_on' => $incoming->publishedOn,
            'publication_year' => $incoming->publicationYear,
            'language' => $incoming->language,
            'country_of_origin' => $incoming->countryOfOrigin,
            'age_rating' => $incoming->ageRating->stricter($mappedRating ?? AgeRating::Unknown),
            'content_warnings' => $incoming->contentWarnings,
            'status' => $incoming->status,
            'page_count' => $incoming->pageCount,
            'chapter_count' => $incoming->chapterCount,
        ];

        foreach ($values as $field => $value) {
            if (in_array($field, $media->locked_fields, true) || $this->isEmpty($value)) {
                continue;
            }

            if ($authoritative || $this->isEmpty($media->getAttribute($field))) {
                $media->setAttribute($field, $value);
            }
        }

        $media->save();
    }

    private function isEmpty(mixed $value): bool
    {
        return blank($value) || $value === AgeRating::Unknown || $value === PublicationStatus::Unknown;
    }
}
