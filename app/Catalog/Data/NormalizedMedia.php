<?php

namespace App\Catalog\Data;

use App\Enums\AgeRating;
use App\Enums\PublicationStatus;
use Carbon\CarbonImmutable;

final readonly class NormalizedMedia
{
    /**
     * @param  list<string>  $alternativeTitles
     * @param  list<NormalizedCreator>  $creators
     * @param  list<string>  $publishers
     * @param  array{title: string, position: ?string}|null  $series
     * @param  list<string>  $genres  Raw provider terms; TaxonomyMapper resolves them.
     * @param  list<string>  $themes
     * @param  list<string>  $tags
     * @param  list<string>  $demographics  Raw provider terms for the editorial demographic (shounen, seinen...); not an age rating.
     * @param  list<string>  $characters
     * @param  list<string>  $isbns  ISBN-13 only.
     * @param  array<string, string>  $crossIds  Other providers' ids, keyed by provider.
     * @param  list<string>  $contentWarnings  Raw provider terms the provider labels as content warnings.
     * @param  array<string, int|float>  $metrics  Popularity signals by name (popularity, favourites, follows, ratings_count, average_rating on a 0-5 scale, pageviews_30d).
     * @param  list<string>  $additionalMediaTypes  Other media type slugs the work also is (see MediaTypeVerdict).
     * @param  list<string>  $credits  Names credited on the work whose roles the provider does not say (a Comic Vine volume credits translators and letterers
     *                                 alike): they help recognise the same work and are never stored as creators.
     */
    public function __construct(
        public string $title,
        public string $mediaTypeSlug,
        public array $alternativeTitles = [],
        public ?string $description = null,
        public array $creators = [],
        public array $publishers = [],
        public ?array $series = null,
        public array $genres = [],
        public array $themes = [],
        public array $tags = [],
        public array $demographics = [],
        public array $characters = [],
        public array $isbns = [],
        public array $crossIds = [],
        public ?int $publicationYear = null,
        public ?CarbonImmutable $publishedOn = null,
        public ?string $language = null,
        public ?string $countryOfOrigin = null,
        public AgeRating $ageRating = AgeRating::Unknown,
        public array $contentWarnings = [],
        public PublicationStatus $status = PublicationStatus::Unknown,
        public ?int $pageCount = null,
        public ?int $chapterCount = null,
        public ?string $coverUrl = null,
        public ?string $externalDeweyCode = null,
        public ?string $sourceUrl = null,
        public bool $isAdult = false,
        public array $metrics = [],
        public array $credits = [],
        public array $additionalMediaTypes = [],
    ) {}

    /**
     * The same record filed under another media type; its additional types were read against the old one, so they go.
     */
    public function withMediaTypeSlug(string $slug): self
    {
        return new self(...[...get_object_vars($this), 'mediaTypeSlug' => $slug, 'additionalMediaTypes' => []]);
    }
}
