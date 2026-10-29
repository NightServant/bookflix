<?php

namespace App\Actions\Library;

use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Put a story at the end of a collection. Idempotent: a story already in it is returned as it is. The collection is locked while it is
 * written, so two quick adds never take the same position. The caller decides who may change the collection and whether they may see the story.
 */
class AddToCollection
{
    public function handle(Collection $collection, Media $media): CollectionItem
    {
        return DB::transaction(function () use ($collection, $media): CollectionItem {
            Collection::query()->whereKey($collection->id)->lockForUpdate()->first();

            $existing = $collection->items()->where('media_id', $media->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            $limit = (int) config('bookflix.collections.max_items');

            if ($collection->items()->count() >= $limit) {
                throw ValidationException::withMessages(['collection' => trans_choice('A collection holds at most :count story.|A collection holds at most :count stories.', $limit)]);
            }

            $last = $collection->items()->max('position');
            $item = $collection->items()->create(['media_id' => $media->id, 'position' => $last === null ? 0 : $last + 1]);

            $collection->touch();

            return $item;
        });
    }
}
