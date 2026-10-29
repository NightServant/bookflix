<?php

namespace App\Discovery\Search;

use App\Catalog\Taxonomy\LegacySlugs;
use App\Enums\PublicationStatus;
use App\Models\DeweyClass;
use App\Models\Media;
use App\Models\MediaType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

/**
 * What a reader narrowed the catalogue to. Several values of one filter mean "any of them" (OR within a group); different filters all have to hold
 * (AND between groups): media type, genres, themes, tropes, demographics, ratings, languages, status and the rest are independent of one another.
 *
 * Genres expand downward: a family selects its subgenres too (and the subgenres linked under it), a subgenre selects only itself, never its siblings.
 * Warnings are only ever excluded: a work is dropped when the warning is present or disputed, and stays when it is unknown or verified absent,
 * because no record of a warning is not a promise that it is absent.
 *
 * Nothing here is an access rule: a filter narrows what a reader may already see, it never unlocks mature or gated content.
 */
final readonly class SearchFilters
{
    public const LENGTHS = ['short', 'medium', 'long'];

    /** The audience ratings a reader can filter by (AgeRating values, unknown left out). */
    public const RATINGS = ['all', 'children', 'teen', 'mature_teen', 'mature', 'adult'];

    /** The most values one list filter keeps, so a crafted payload cannot exceed the database's bind-parameter limit. */
    public const MAX_VALUES = 50;

    /**
     * @param  list<string>  $mediaTypes  media type slugs
     * @param  list<string>  $genres  genre or genre family slugs; a family includes its descendants
     * @param  list<string>  $themes  theme slugs
     * @param  list<string>  $languages  ISO 639-1 codes
     * @param  list<string>  $statuses  PublicationStatus values
     * @param  string|null  $length  short|medium|long, by page count, or by chapter count when there are no pages
     * @param  list<int>  $creatorIds
     * @param  list<int>  $publisherIds
     * @param  string|null  $deweyCode  the class and everything below it, public primary classes only
     * @param  list<string>  $tropes  trope slugs
     * @param  list<string>  $demographics  editorial demographic slugs (shonen, seinen, young-adult...); not an age rating
     * @param  list<string>  $ratings  AgeRating values (all, children, teen, mature_teen, mature, adult); `unknown` is not a rating
     * @param  list<string>  $excludeWarnings  content warning slugs: media with any of them present or disputed are left out
     */
    public function __construct(
        public array $mediaTypes = [],
        public array $genres = [],
        public array $themes = [],
        public array $languages = [],
        public ?int $yearFrom = null,
        public ?int $yearTo = null,
        public ?float $minRating = null,
        public array $statuses = [],
        public ?string $length = null,
        public array $creatorIds = [],
        public array $publisherIds = [],
        public ?string $deweyCode = null,
        public array $tropes = [],
        public array $demographics = [],
        public array $ratings = [],
        public array $excludeWarnings = [],
    ) {}

    /**
     * Build filters from untrusted input (a query string, a Livewire property): unknown keys are ignored, values are coerced, and invalid ones dropped.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        // Slugs from before the taxonomy (a bookmarked ?filters[genres][]=action) are upgraded to the nodes that replaced them.
        $legacy = app(LegacySlugs::class)->upgrade(['genres' => self::strings($input['genres'] ?? []), 'themes' => self::strings($input['themes'] ?? [])]);

        return new self(
            mediaTypes: self::strings($input['mediaTypes'] ?? []),
            genres: array_slice($legacy['genres'], 0, self::MAX_VALUES),
            themes: array_slice($legacy['themes'], 0, self::MAX_VALUES),
            languages: self::strings($input['languages'] ?? []),
            yearFrom: filter_var($input['yearFrom'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            yearTo: filter_var($input['yearTo'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            minRating: filter_var($input['minRating'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE),
            statuses: array_values(array_filter(self::strings($input['statuses'] ?? []), fn (string $status): bool => PublicationStatus::tryFrom($status) !== null)),
            length: in_array($input['length'] ?? null, self::LENGTHS, true) ? $input['length'] : null,
            creatorIds: self::ids($input['creatorIds'] ?? []),
            publisherIds: self::ids($input['publisherIds'] ?? []),
            deweyCode: self::strings($input['deweyCode'] ?? [])[0] ?? null,
            tropes: self::strings($input['tropes'] ?? []),
            demographics: self::strings($input['demographics'] ?? []),
            ratings: array_values(array_filter(self::strings($input['ratings'] ?? []), fn (string $rating): bool => in_array($rating, self::RATINGS, true))),
            excludeWarnings: self::strings($input['excludeWarnings'] ?? []),
        );
    }

    /**
     * The filters that are set, in the shape fromArray() reads back.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter(get_object_vars($this), fn (mixed $value): bool => $value !== null && $value !== []);
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /**
     * Narrow a media query, in SQL, so a search leg ranks only what the reader can see.
     *
     * @param  Builder<Media>  $query
     * @return Builder<Media>
     */
    public function apply(Builder $query): Builder
    {
        return $query
            ->when($this->mediaTypes, fn (Builder $media, array $slugs) => $media->whereIn('media.media_type_id', MediaType::query()->whereIn('slug', $slugs)->select('id')))
            ->when($this->genres, fn (Builder $media, array $slugs) => $media->inGenres($slugs))
            ->when($this->themes, fn (Builder $media, array $slugs) => $media->whereHas('themes', fn (Builder $themes) => $themes->whereIn('themes.slug', $slugs)))
            ->when($this->tropes, fn (Builder $media, array $slugs) => $media->whereHas('tropes', fn (Builder $tropes) => $tropes->whereIn('tropes.slug', $slugs)))
            ->when($this->demographics, fn (Builder $media, array $slugs) => $media->whereHas('demographics', fn (Builder $demographics) => $demographics->whereIn('demographics.slug', $slugs)))
            ->when($this->ratings, fn (Builder $media, array $ratings) => $media->whereIn('media.age_rating', $ratings))
            ->when($this->excludeWarnings, fn (Builder $media, array $slugs) => $media->withoutWarnings($slugs))
            ->when($this->languages, fn (Builder $media, array $codes) => $media->whereIn('media.language', $codes))
            ->when($this->yearFrom, fn (Builder $media, int $year) => $media->where('media.publication_year', '>=', $year))
            ->when($this->yearTo, fn (Builder $media, int $year) => $media->where('media.publication_year', '<=', $year))
            ->when($this->minRating !== null, fn (Builder $media) => $media->where('media.average_rating', '>=', $this->minRating))
            ->when($this->statuses, fn (Builder $media, array $statuses) => $media->whereIn('media.status', $statuses))
            ->when($this->length, fn (Builder $media, string $length) => $this->applyLength($media, $length))
            ->when($this->creatorIds, fn (Builder $media, array $ids) => $media->whereHas('creators', fn (Builder $creators) => $creators->whereIn('creators.id', $ids)))
            ->when($this->publisherIds, fn (Builder $media, array $ids) => $media->whereHas('publishers', fn (Builder $publishers) => $publishers->whereIn('publishers.id', $ids)))
            ->when($this->deweyCode, fn (Builder $media, string $code) => $this->applyDewey($media, $code));
    }

    /**
     * Pages decide the bucket when the media has a page count, chapters when it has none. Media with neither is in no bucket.
     *
     * @param  Builder<Media>  $media
     * @return Builder<Media>
     */
    private function applyLength(Builder $media, string $length): Builder
    {
        $buckets = config('bookflix.length_buckets');
        $within = fn (Builder $inner, string $column, array $limits): Builder => match ($length) {
            'short' => $inner->where($column, '<=', $limits['short']),
            'medium' => $inner->where($column, '>', $limits['short'])->where($column, '<=', $limits['medium']),
            default => $inner->where($column, '>', $limits['medium']),
        };

        return $media->where(fn (Builder $either) => $either
            ->where(fn (Builder $pages) => $within($pages, 'media.page_count', $buckets['pages']))
            ->orWhere(fn (Builder $chapters) => $within($chapters->whereNull('media.page_count'), 'media.chapter_count', $buckets['chapters'])));
    }

    /**
     * An unknown code matches nothing, rather than everything.
     *
     * @param  Builder<Media>  $media
     * @return Builder<Media>
     */
    private function applyDewey(Builder $media, string $code): Builder
    {
        $class = DeweyClass::where('code', $code)->first();

        return $class ? $media->inDeweySubtree($class) : $media->whereRaw('1 = 0');
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        // Invalid UTF-8 would reach json_encode and Postgres as a 500; a value that does not survive mb_scrub is dropped.
        $strings = array_map(fn (mixed $item): string => is_scalar($item) && mb_scrub((string) $item) === (string) $item ? trim((string) $item) : '', Arr::wrap($value));

        return array_slice(array_values(array_unique(array_filter($strings, fn (string $item): bool => $item !== ''))), 0, self::MAX_VALUES);
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        $ids = array_map(fn (mixed $item): ?int => filter_var($item, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE), Arr::wrap($value));

        return array_slice(array_values(array_unique(array_filter($ids, fn (?int $id): bool => $id !== null))), 0, self::MAX_VALUES);
    }
}
