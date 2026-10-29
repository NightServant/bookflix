<?php

namespace App\Catalog\Classification;

/**
 * Everything a provider record says that bears on what kind of work it is. Nothing here is required: the classifier weighs what it is given.
 */
final readonly class ClassificationSignals
{
    /**
     * @param  string  $provider  The provider key (anilist, mangadex, mangaupdates, openlibrary, comicvine, metron, hardcover); empty for a work no provider owns.
     * @param  string|null  $format  The provider's own format or type field, verbatim: the AniList `format`, the MangaUpdates `type`, the Metron `series_type`.
     * @param  list<string>  $alternativeTitles
     * @param  string|null  $description  The description, or the deck when that is all there is.
     * @param  list<string>  $subjects  Every raw genre, subject and tag term of the record.
     * @param  list<string>  $publishers  Publishers and imprints.
     * @param  list<string>  $creatorRoles  The roles credited on the work ("author", "penciller", "colorist"...), any case.
     * @param  int|null  $chapterCount  Chapters, or issues for a comic series.
     * @param  string|null  $country  ISO 3166-1 alpha-2 country of origin.
     * @param  string|null  $language  ISO 639-1 original language ("zh-hk" is read as Chinese).
     */
    public function __construct(
        public string $provider = '',
        public ?string $format = null,
        public string $title = '',
        public array $alternativeTitles = [],
        public ?string $description = null,
        public array $subjects = [],
        public array $publishers = [],
        public array $creatorRoles = [],
        public ?int $pageCount = null,
        public ?int $chapterCount = null,
        public ?string $country = null,
        public ?string $language = null,
    ) {}
}
