<?php

namespace App\Catalog\Ingestion;

use App\Catalog\Data\NormalizedMedia;
use App\Enums\AgeRating;
use App\Enums\PublicationStatus;
use App\Models\Media;
use Illuminate\Support\Facades\DB;

class Enricher
{
    /**
     * Fill a media's own columns from a provider record. The names double as the values admins can lock.
     *
     * Precedence (A9): an admin-locked field is never touched, then what is already stored beats a newer
     * provider's value. The media's first provider is the exception: its re-syncs keep the record current,
     * which also stops two providers overwriting each other on every sync.
     */
    public function apply(Media $media, NormalizedMedia $incoming, string $provider): void
    {
        $authoritative = $this->isFirstProvider($media, $provider);

        $values = [
            'title' => trim($incoming->title),
            'alternative_titles' => $incoming->alternativeTitles,
            'description' => $incoming->description,
            'cover_url' => $incoming->coverUrl,
            'published_on' => $incoming->publishedOn,
            'publication_year' => $incoming->publicationYear,
            'language' => $incoming->language,
            'country_of_origin' => $incoming->countryOfOrigin,
            'age_rating' => $incoming->ageRating,
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

    private function isFirstProvider(Media $media, string $provider): bool
    {
        $first = DB::table('media_external_ids')
            ->join('external_sources', 'external_sources.id', '=', 'media_external_ids.external_source_id')
            ->where('media_external_ids.media_id', $media->id)
            ->where('external_sources.key', '!=', 'isbn')
            ->orderBy('media_external_ids.id')
            ->value('external_sources.key');

        return $first === null || $first === $provider;
    }

    private function isEmpty(mixed $value): bool
    {
        return blank($value) || $value === AgeRating::Unknown || $value === PublicationStatus::Unknown;
    }
}
