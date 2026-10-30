<?php

namespace App\Actions\Library;

use App\Models\Collection;
use App\Models\CollectionItem;
use Illuminate\Support\Facades\DB;

/**
 * Take an item out of a collection and close the gap, so the positions stay contiguous from 0. An item that is not in this collection (or is
 * already gone) is left alone. The caller decides who may change the collection.
 */
class RemoveFromCollection
{
    public function handle(Collection $collection, CollectionItem $item): void
    {
        DB::transaction(function () use ($collection, $item): void {
            Collection::query()->whereKey($collection->id)->lockForUpdate()->first();

            $current = $collection->items()->find($item->id);

            if ($current === null) {
                return;
            }

            $current->delete();
            $collection->items()->where('position', '>', $current->position)->decrement('position');
            $collection->touch();
        });
    }
}
