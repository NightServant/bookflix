<?php

namespace App\Discovery\Search;

use App\Enums\PublicationStatus;
use App\Models\DeweyClass;
use App\Models\Media;
use App\Models\MediaType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

/**
 * What a reader narrowed the catalogue to. Several values of one filter mean "any of them"; different filters all have to hold.
 */
final readonly class SearchFilters
{
    public const LENGTHS = ['short', 'medium', 'long'];

    /**
     * @param  list<string>  $mediaTypes  media type slugs
     * @param  list<string>  $genres  genre slugs
     * @param  list<string>  $themes  theme slugs
     * @param  list<string>  $languages  ISO 639-1 codes
     * @param  list<string>  $statuses  PublicationStatus values
     * @param  string|null  $length  short|medium|long, by page count, or by chapter count when there are no pages
     * @param  list<int>  $creatorIds
     * @param  list<int>  $publisherIds
     * @param  string|null  $deweyCode  the class and everything below it, public primary classes only
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
    ) {}

    /**
     * Build filters from untrusted input (a query string, a Livewire property): unknown keys are ignored, values are coerced, and invalid ones dropped.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            mediaTypes: self::strings($input['mediaTypes'] ?? []),
            genres: self::strings($input['genres'] ?? []),
            themes: self::strings($input['themes'] ?? []),
            languages: self::strings($input['languages'] ?? []),
            yearFrom: filter_var($input['yearFrom'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            yearTo: filter_var($input['yearTo'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            minRating: filter_var($input['minRating'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE),
            statuses: array_values(array_filter(self::strings($input['statuses'] ?? []), fn (string $status): bool => PublicationStatus::tryFrom($status) !== null)),
            length: in_array($input['length'] ?? null, self::LENGTHS, true) ? $input['length'] : null,
            creatorIds: self::ids($input['creatorIds'] ?? []),
            publisherIds: self::ids($input['publisherIds'] ?? []),
            deweyCode: self::strings($input['deweyCode'] ?? [])[0] ?? null,
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
            ->when($this->genres, fn (Builder $media, array $slugs) => $media->whereHas('genres', fn (Builder $genres) => $genres->whereIn('genres.slug', $slugs)))
            ->when($this->themes, fn (Builder $media, array $slugs) => $media->whereHas('themes', fn (Builder $themes) => $themes->whereIn('themes.slug', $slugs)))
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
        $strings = array_map(fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', Arr::wrap($value));

        return array_values(array_unique(array_filter($strings, fn (string $item): bool => $item !== '')));
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        $ids = array_map(fn (mixed $item): ?int => filter_var($item, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE), Arr::wrap($value));

        return array_values(array_unique(array_filter($ids, fn (?int $id): bool => $id !== null)));
    }
}
