<?php

namespace App\Actions\Library;

use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Move an item to a place in its collection and rewrite the positions contiguously from 0. The place is counted among the stories the viewer
 * can see (that is the list they drag in), so one hidden by the mature gate keeps its seat between its neighbours: the item lands in front of
 * the story that is now at that place, or at the end. A place past either end is clamped. An item that is not in this collection is left
 * alone. The caller decides who may change the collection.
 */
class MoveCollectionItem
{
    public function handle(Collection $collection, CollectionItem $item, int $position, User $viewer): void
    {
        DB::transaction(function () use ($collection, $item, $position, $viewer): void {
            Collection::query()->whereKey($collection->id)->lockForUpdate()->first();

            /** @var array<int, int> $positions item id => stored position, in order */
            $positions = $collection->items()->orderBy('position')->orderBy('id')->pluck('position', 'id')->all();

            if (! array_key_exists($item->id, $positions)) {
                return;
            }

            $seen = array_flip($collection->items()->visibleTo($viewer)->pluck('collection_items.id')->all());
            $others = array_values(array_diff(array_keys($positions), [$item->id]));
            $visibleOthers = array_values(array_filter($others, fn (int $id): bool => isset($seen[$id])));

            $before = $visibleOthers[max(0, min($position, count($visibleOthers)))] ?? null;
            array_splice($others, $before === null ? count($others) : (int) array_search($before, $others, true), 0, [$item->id]);

            foreach ($others as $index => $id) {
                if ($positions[$id] !== $index) {
                    $collection->items()->whereKey($id)->update(['position' => $index]);
                }
            }

            $collection->touch();
        });
    }
}
