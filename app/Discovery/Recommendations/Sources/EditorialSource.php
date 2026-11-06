<?php

namespace App\Discovery\Recommendations\Sources;

use App\Enums\ReasonType;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Staff picks: the titles of the editorial collections by their position in the collection, the first scoring 1. A reader's own and public
 * collections are never read, only those staff marked editorial.
 */
class EditorialSource extends Source
{
    public function key(): string
    {
        return 'editorial';
    }

    protected function gather(User $user, int $limit): Collection
    {
        $ids = array_values($this->eligible($user)
            ->join('collection_items', 'collection_items.media_id', '=', 'media.id')
            ->join('collections', 'collections.id', '=', 'collection_items.collection_id')
            ->where('collections.is_editorial', true)
            ->orderBy('collection_items.position')->orderBy('collection_items.collection_id')->orderBy('collection_items.id')
            ->pluck('media.id')->unique()->take($limit)->map(fn ($id): int => (int) $id)->all());

        return $this->byPosition($ids, ReasonType::Editorial);
    }
}
