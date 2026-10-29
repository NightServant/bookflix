<?php

namespace App\Models;

use Database\Factories\GenreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A genre family (no parent: Fantasy, Romance...) or a subgenre of one. Selecting a family includes everything below it;
 * a work tagged with a subgenre is not also tagged with its family (the family is implied, so parents are never double stored).
 *
 * A subgenre the spec lists under two families is one row: `parent_id` is its primary family, `secondaryParents` the others.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int|null $parent_id
 * @property string|null $group The display group inside the family ("Progression and Eastern fantasy").
 * @property int $sort
 * @property bool $is_mature Mature-gated: only offered to readers who opted in to mature content.
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'parent_id', 'group', 'sort', 'is_mature', 'description'])]
class Genre extends Model
{
    /** @use HasFactory<GenreFactory> */
    use HasFactory;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort' => 0,
        'is_mature' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_mature' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * SQL for the ids of the genres whose slugs are bound as one text array (see slugArray()) and of everything below them: children,
     * and subgenres linked under them as an extra parent. Postgres walks the tree with one recursive query, so any depth works.
     */
    public const TREE_IDS_SQL = <<<'SQL'
        with recursive tree(id) as (
            select id from genres where slug = any(?::text[])
            union
            select edge.genre_id from tree
            join (
                select id as genre_id, parent_id from genres where parent_id is not null
                union all
                select genre_id, parent_id from genre_secondary_parents
            ) edge on edge.parent_id = tree.id
        )
        select id from tree
        SQL;

    /**
     * The single binding for TREE_IDS_SQL: slugs as a Postgres text array literal, quoted so no slug can end it early.
     *
     * @param  list<string>  $slugs
     */
    public static function slugArray(array $slugs): string
    {
        return '{'.implode(',', array_map(fn (string $slug): string => '"'.addcslashes(str_replace("\0", '', $slug), '\\"').'"', $slugs)).'}';
    }

    /**
     * SQL that keeps a media row (joined as `media`) out of a count when the reader may not see it: adult-rated, or tagged with a mature genre.
     * Takes one boolean binding, true for a reader who opted in to mature titles. The same rule as Media::scopeWithoutMature.
     */
    private const GATED_MEDIA_SQL = <<<'SQL'
        (? or (media.age_rating <> 'adult' and not exists (
            select 1 from media_genres gated_tags join genres gated on gated.id = gated_tags.genre_id
            where gated_tags.media_id = media.id and gated.is_mature
        )))
        SQL;

    /**
     * Live story counts for the whole tree in one query: `families` is how many stories each family has, counting its subgenres and the ones linked
     * under it (a story in several subgenres of one family counts once for it), and `tags` how many carry each genre itself, subgenres and families
     * alike. Without `$includeMature` the mature-gated families are left out and so are the gated stories (adult-rated, or tagged with a mature genre),
     * so the numbers a gated reader sees match the stories they can reach.
     *
     * @return array{families: array<int, int>, tags: array<int, int>}
     */
    public static function counts(bool $includeMature = false): array
    {
        $rows = DB::select(str_replace('{gate}', self::GATED_MEDIA_SQL, <<<'SQL'
            with recursive edges(parent_id, genre_id) as (
                select parent_id, id from genres where parent_id is not null
                union all
                select parent_id, genre_id from genre_secondary_parents
            ),
            tree(root_id, genre_id) as (
                select id, id from genres where parent_id is null and (is_mature = false or ?)
                union
                select tree.root_id, edges.genre_id from tree join edges on edges.parent_id = tree.genre_id
            )
            select 'family' as kind, tree.root_id as id, count(distinct media_genres.media_id) as media_count
            from tree
            join media_genres on media_genres.genre_id = tree.genre_id
            join media on media.id = media_genres.media_id and media.merged_into_id is null and media.deleted_at is null and {gate}
            group by tree.root_id
            union all
            select 'tag', media_genres.genre_id, count(distinct media_genres.media_id)
            from media_genres
            join media on media.id = media_genres.media_id and media.merged_into_id is null and media.deleted_at is null and {gate}
            group by media_genres.genre_id
            SQL), array_fill(0, 3, $includeMature ? 't' : 'f'));

        $counts = ['families' => [], 'tags' => []];

        foreach ($rows as $row) {
            $counts[$row->kind === 'family' ? 'families' : 'tags'][(int) $row->id] = (int) $row->media_count;
        }

        return $counts;
    }

    /**
     * How many live stories each genre family has, counting its subgenres and the ones linked under it: `[family id => count]`, biggest first
     * (see counts() for what is left out without `$includeMature`).
     *
     * @return array<int, int>
     */
    public static function familyCounts(bool $includeMature = false): array
    {
        $families = self::counts($includeMature)['families'];

        uksort($families, fn (int $a, int $b): int => [$families[$b], $a] <=> [$families[$a], $b]);

        return $families;
    }

    /**
     * How many live stories carry each genre itself: `[genre id => count]` (see counts()).
     *
     * @return array<int, int>
     */
    public static function tagCounts(bool $includeMature = false): array
    {
        return self::counts($includeMature)['tags'];
    }

    /**
     * Genre families: the rows with no parent.
     *
     * @param  Builder<Genre>  $query
     */
    public function scopeFamilies(Builder $query): void
    {
        $query->whereNull('genres.parent_id');
    }

    /**
     * Genres a reader may be offered: all of them, or only the ones that are not mature-gated.
     *
     * @param  Builder<Genre>  $query
     */
    public function scopeVisible(Builder $query, bool $includeMature = false): void
    {
        $query->when(! $includeMature, fn (Builder $genres) => $genres->where('genres.is_mature', false));
    }

    public function isFamily(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * @return BelongsTo<Genre, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Genre, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('name');
    }

    /**
     * The other families this subgenre is listed under.
     *
     * @return BelongsToMany<Genre, $this, Pivot, 'pivot'>
     */
    public function secondaryParents(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'genre_secondary_parents', 'genre_id', 'parent_id')->withPivot(['group', 'sort'])->withTimestamps();
    }

    /**
     * Subgenres from other families that are also listed under this family.
     *
     * @return BelongsToMany<Genre, $this, Pivot, 'pivot'>
     */
    public function secondaryChildren(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'genre_secondary_parents', 'parent_id', 'genre_id')->withPivot(['group', 'sort'])->withTimestamps();
    }

    /**
     * @return BelongsToMany<Media, $this, Pivot, 'pivot'>
     */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'media_genres')->withTimestamps();
    }
}
