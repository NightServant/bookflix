<?php

namespace App\Models;

use App\Enums\PublicationStatus;
use Database\Factories\SeriesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property PublicationStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'slug', 'description', 'status'])]
class Series extends Model
{
    /** @use HasFactory<SeriesFactory> */
    use HasFactory;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'unknown',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PublicationStatus::class,
        ];
    }

    /**
     * @return BelongsToMany<Media, $this, Pivot, 'pivot'>
     */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'media_series')->withPivot(['position', 'label'])->withTimestamps();
    }

    /**
     * The volumes in reading order: by position, those without one last, then by title.
     *
     * @return BelongsToMany<Media, $this, Pivot, 'pivot'>
     */
    public function orderedMedia(): BelongsToMany
    {
        return $this->media()->orderByRaw('media_series.position asc nulls last')->orderBy('media.title')->orderBy('media.id');
    }

    /**
     * The volumes `$radius` either side of `$media` in reading order (the first `2 * $radius + 1` when it is not in the series), in one
     * query. Positioned in SQL, so a 1,000 volume series never hydrates more than the window. Volumes the reader may not see (mature and adult
     * titles, unless they opted in) are not volumes of the window at all: they are neither listed nor counted for its positions.
     *
     * @return Collection<int, Media>
     */
    public function window(Media $media, int $radius = 5, ?User $viewer = null): Collection
    {
        $ranked = $this->media()->visibleTo($viewer)->select('media.id')
            ->selectRaw('row_number() over (order by media_series.position asc nulls last, media.title, media.id) as rank')
            ->toBase();

        return $this->orderedMedia()->visibleTo($viewer)
            ->whereIn('media.id', fn ($ids) => $ids->select('r.id')->fromSub($ranked, 'r')->whereRaw(
                'abs(r.rank - coalesce((select x.rank from ('.$ranked->toSql().') x where x.id = ?), ?)) <= ?',
                [...$ranked->getBindings(), $media->id, $radius + 1, $radius],
            ))
            ->get();
    }
}
