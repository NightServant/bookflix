<?php

namespace App\Models;

use App\Enums\AgeRating;
use App\Enums\PublicationStatus;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
])]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory, SoftDeletes;

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
}
