<?php

namespace App\Models;

use App\Classification\DeweyCounts;
use App\Discovery\Search\SearchTextBuilder;
use App\Enums\AgeRating;
use App\Enums\ClassificationType;
use App\Enums\PublicationStatus;
use App\Enums\ReviewStatus;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Searchable;

/**
 * @property int $id
 * @property string $slug
 * @property int $media_type_id
 * @property string $title
 * @property list<string> $alternative_titles
 * @property string|null $description
 * @property string|null $cover_url
 * @property string|null $cover_path
 * @property Carbon|null $published_on
 * @property int|null $publication_year
 * @property string|null $language
 * @property string|null $country_of_origin
 * @property string|null $volume_number
 * @property string|null $chapter_number
 * @property string|null $issue_number
 * @property string|null $format
 * @property AgeRating $age_rating
 * @property list<string> $content_warnings
 * @property PublicationStatus $status
 * @property int|null $page_count
 * @property int|null $chapter_count
 * @property string|null $average_rating
 * @property int $ratings_count
 * @property string $search_text
 * @property list<string> $locked_fields
 * @property int|null $merged_into_id
 * @property int|null $primary_external_source_id The provider whose re-syncs may overwrite this media; kept through merges.
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'slug',
    'media_type_id',
    'title',
    'alternative_titles',
    'description',
    'cover_url',
    'cover_path',
    'published_on',
    'publication_year',
    'language',
    'country_of_origin',
    'volume_number',
    'chapter_number',
    'issue_number',
    'format',
    'age_rating',
    'content_warnings',
    'status',
    'page_count',
    'chapter_count',
    'average_rating',
    'ratings_count',
    'search_text',
    'locked_fields',
    'merged_into_id',
    'primary_external_source_id',
])]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory, Searchable, SoftDeletes;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'alternative_titles' => '[]',
        'content_warnings' => '[]',
        'locked_fields' => '[]',
        'age_rating' => 'unknown',
        'status' => 'unknown',
        'ratings_count' => 0,
        'search_text' => '',
    ];

    protected static function booted(): void
    {
        static::creating(function (Media $media): void {
            if (blank($media->slug)) {
                $media->slug = static::uniqueSlug($media->title, $media->publication_year);
            }
        });

        // A removed (merges included) or restored media enters or leaves every class count, so the cached counts are dropped once the change is committed.
        $flushCounts = fn () => DB::afterCommit(fn () => app(DeweyCounts::class)->flush());

        static::deleted($flushCounts);
        static::restored($flushCounts);
    }

    /**
     * A slug no media (soft-deleted included) uses yet: the title slug, then with the year, then numbered.
     */
    public static function uniqueSlug(string $title, ?int $year = null): string
    {
        $slug = Str::slug($title) ?: 'untitled';

        if ($year !== null && static::slugIsTaken($slug)) {
            $slug .= "-{$year}";
        }

        $base = $slug;

        for ($number = 2; static::slugIsTaken($slug); $number++) {
            $slug = "{$base}-{$number}";
        }

        return $slug;
    }

    protected static function slugIsTaken(string $slug): bool
    {
        return static::withTrashed()->where('slug', $slug)->exists();
    }

    /**
     * The media a public slug points at: the media itself, or the winner when the slug was merged away.
     * Null for an unknown slug or one that was plainly deleted (a merge target that was deleted too counts as gone).
     */
    public static function resolveSlug(string $slug): ?self
    {
        $media = static::withTrashed()->where('slug', $slug)->first();

        if ($media?->merged_into_id !== null) {
            return static::find($media->merged_into_id);
        }

        return $media?->trashed() ? null : $media;
    }

    /**
     * Rebuild the lowercased, accent-folded names that search reads (see SearchTextBuilder), after the relations may have changed.
     */
    public function refreshSearchText(): void
    {
        $this->load(SearchTextBuilder::RELATIONS);

        $this->update(['search_text' => app(SearchTextBuilder::class)->build($this)]);
    }

    /**
     * Scout's database engine searches the table itself, and only this column, through the `media_search_fts` index.
     *
     * @return array{search_text: string}
     */
    #[SearchUsingFullText(['search_text'], ['language' => 'simple'])]
    public function toSearchableArray(): array
    {
        return ['search_text' => $this->search_text];
    }

    /**
     * Merged and deleted media stay out of every index.
     */
    public function shouldBeSearchable(): bool
    {
        return ! $this->trashed() && $this->merged_into_id === null;
    }

    /**
     * Media that is not a merge loser: the live, public catalogue (deleted media is already hidden by the soft-delete scope).
     *
     * @param  Builder<Media>  $query
     */
    public function scopeUnmerged(Builder $query): void
    {
        $query->whereNull('media.merged_into_id');
    }

    /**
     * The stories readers rate most, the newest breaking ties. Interim: Phase 6 redefines it over `media_popularity`.
     *
     * @param  Builder<Media>  $query
     */
    public function scopeTrending(Builder $query): void
    {
        $query->unmerged()
            ->orderByDesc('media.ratings_count')
            ->orderByRaw('media.published_on desc nulls last')
            ->orderByDesc('media.id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'alternative_titles' => 'array',
            'content_warnings' => 'array',
            'locked_fields' => 'array',
            'published_on' => 'date',
            'age_rating' => AgeRating::class,
            'status' => PublicationStatus::class,
        ];
    }

    /**
     * @return BelongsTo<MediaType, $this>
     */
    public function mediaType(): BelongsTo
    {
        return $this->belongsTo(MediaType::class);
    }

    /**
     * @return BelongsToMany<Creator, $this, MediaCreator, 'pivot'>
     */
    public function creators(): BelongsToMany
    {
        return $this->belongsToMany(Creator::class, 'media_creators')
            ->using(MediaCreator::class)
            ->withPivot(['role', 'position'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Publisher, $this, Pivot, 'pivot'>
     */
    public function publishers(): BelongsToMany
    {
        return $this->belongsToMany(Publisher::class, 'media_publishers')->withPivot('region')->withTimestamps();
    }

    /**
     * @return BelongsToMany<Series, $this, Pivot, 'pivot'>
     */
    public function series(): BelongsToMany
    {
        return $this->belongsToMany(Series::class, 'media_series')->withPivot(['position', 'label'])->withTimestamps();
    }

    /**
     * @return BelongsToMany<Genre, $this, Pivot, 'pivot'>
     */
    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'media_genres')->withTimestamps();
    }

    /**
     * @return BelongsToMany<Theme, $this, Pivot, 'pivot'>
     */
    public function themes(): BelongsToMany
    {
        return $this->belongsToMany(Theme::class, 'media_themes')->withTimestamps();
    }

    /**
     * @return BelongsToMany<Tag, $this, Pivot, 'pivot'>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'media_tags')->withTimestamps();
    }

    /**
     * @return BelongsToMany<Character, $this, Pivot, 'pivot'>
     */
    public function characters(): BelongsToMany
    {
        return $this->belongsToMany(Character::class, 'media_characters')->withPivot('role')->withTimestamps();
    }

    /**
     * @return HasMany<MediaExternalId, $this>
     */
    public function externalIds(): HasMany
    {
        return $this->hasMany(MediaExternalId::class);
    }

    /**
     * Related media (sequel, adaptation, ...); the kind of relation is the pivot `relation`.
     *
     * @return BelongsToMany<Media, $this, Pivot, 'pivot'>
     */
    public function relations(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'media_relations', 'media_id', 'related_media_id')
            ->withPivot('relation')
            ->withTimestamps();
    }

    /**
     * @return HasMany<MediaDeweyClass, $this>
     */
    public function deweyClasses(): HasMany
    {
        return $this->hasMany(MediaDeweyClass::class);
    }

    /**
     * The live primary class: any primary that has not been rejected.
     *
     * @return HasOne<MediaDeweyClass, $this>
     */
    public function primaryDewey(): HasOne
    {
        return $this->hasOne(MediaDeweyClass::class)
            ->where('classification_type', ClassificationType::Primary)
            ->where('review_status', '!=', ReviewStatus::Rejected);
    }

    /**
     * The primary class a reader may see on the story (accepted, or pending with enough confidence).
     *
     * @return HasOne<MediaDeweyClass, $this>
     */
    public function publicPrimaryDewey(): HasOne
    {
        return $this->hasOne(MediaDeweyClass::class)->publicPrimary();
    }

    /**
     * Media whose public primary class is the given class or one below it.
     *
     * @param  Builder<Media>  $query
     */
    public function scopeInDeweySubtree(Builder $query, DeweyClass $class): void
    {
        $query->whereHas('deweyClasses', fn (Builder $rows) => $rows->publicPrimary()->whereIn('dewey_class_id', DeweyClass::subtree($class)->select('id')));
    }
}
