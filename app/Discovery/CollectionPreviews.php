<?php

namespace App\Discovery;

use App\Models\Collection;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What a list of collections shows of each one: how many stories the viewer may see in it, and the covers of the first few. Both go through
 * Media::visibleTo, so a story behind the mature gate is neither counted nor pictured; two queries for any number of collections.
 */
class CollectionPreviews
{
    /** How many covers a row shows. */
    public const COVERS = 4;

    /**
     * @param  EloquentCollection<int, Collection>  $collections
     * @return array<int, array{count: int, covers: list<string>}> by collection id
     */
    public function for(EloquentCollection $collections, ?User $viewer): array
    {
        $ids = $collections->modelKeys();

        if ($ids === []) {
            return [];
        }

        $visible = fn () => Media::query()->unmerged()->visibleTo($viewer)
            ->join('collection_items', 'collection_items.media_id', '=', 'media.id')
            ->whereIn('collection_items.collection_id', $ids);

        $counts = $visible()->groupBy('collection_items.collection_id')->selectRaw('collection_items.collection_id, count(*) as total')->pluck('total', 'collection_id');

        $covers = DB::query()
            ->fromSub(
                $visible()->whereNotNull('media.cover_url')->selectRaw('collection_items.collection_id, media.cover_url, row_number() over (partition by collection_items.collection_id order by collection_items.position, collection_items.id) as place')->toBase(),
                'ranked',
            )
            ->where('place', '<=', self::COVERS)
            ->orderBy('collection_id')->orderBy('place')
            ->get()
            ->groupBy('collection_id');

        $previews = [];

        foreach ($ids as $id) {
            $previews[(int) $id] = [
                'count' => (int) ($counts[$id] ?? 0),
                'covers' => array_values(array_map(fn (stdClass $row): string => (string) $row->cover_url, ($covers[$id] ?? collect())->all())),
            ];
        }

        return $previews;
    }
}
