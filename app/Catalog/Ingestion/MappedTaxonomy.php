<?php

namespace App\Catalog\Ingestion;

use App\Enums\AgeRating;

/**
 * What a record's provider terms resolved to in the Bookflix taxonomy. Genres hold the most specific node the provider named:
 * a subgenre implies its family, so the family is never added next to it.
 */
final readonly class MappedTaxonomy
{
    /**
     * @param  list<string>  $genres  Genre and subgenre slugs.
     * @param  list<string>  $themes
     * @param  list<string>  $tropes
     * @param  list<string>  $demographics
     * @param  list<string>  $warnings  Content warning slugs, each reported by the provider as present.
     * @param  array<string, string>  $tags  Terms nothing mapped, as slug to display name.
     */
    public function __construct(
        public array $genres = [],
        public array $themes = [],
        public array $tropes = [],
        public array $demographics = [],
        public array $warnings = [],
        public ?AgeRating $rating = null,
        public array $tags = [],
    ) {}
}
