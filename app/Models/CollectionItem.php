<?php

namespace App\Models;

use Database\Factories\CollectionItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One story in one collection, at a position (contiguous from 0, see the actions in App\Actions\Library that are its only writers).
 *
 * @property int $id
 * @property int $collection_id
 * @property int $media_id
 * @property int $position
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['collection_id', 'media_id', 'position', 'note'])]
class CollectionItem extends Model
{
    /** @use HasFactory<CollectionItemFactory> */
    use HasFactory;

    /**
     * Items whose story this reader may see: not deleted, not merged away, and past the mature gate (see Media::scopeVisibleTo).
     * A gated item stays stored and comes back when the reader opts in; it is only left out of lists and counts.
     *
     * @param  Builder<CollectionItem>  $query
     */
    public function scopeVisibleTo(Builder $query, ?User $viewer): void
    {
        $query->whereHas('media', fn (Builder $media) => $media->unmerged()->visibleTo($viewer));
    }

    /**
     * @return BelongsTo<Collection, $this>
     */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
