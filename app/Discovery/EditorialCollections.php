<?php

namespace App\Discovery;

use App\Models\Collection;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The editorial collections (staff picks, set by an admin): the themed rows Discover and Home show, and the list the Collections page offers.
 * A collection counts only when the reader may see at least one story in it (Media::visibleTo), and a row holds only those stories.
 */
class EditorialCollections
{
    /** The most stories on a row. */
    private const PER_SHELF = 20;

    /**
     * The editorial collections with something in them for this reader, the most recently changed first.
     *
     * @return EloquentCollection<int, Collection>
     */
    public function collections(?User $viewer, int $limit = 12): EloquentCollection
    {
        return Collection::query()
            ->where('is_editorial', true)
            ->whereHas('items', fn ($items) => $items->visibleTo($viewer))
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * One shelf per collection: its name as the title, "Staff pick" as the eyebrow, its description, and its stories in order, each with what a
     * tile and its preview read.
     *
     * @return SupportCollection<int, Shelf>
     */
    public function shelves(?User $viewer, int $limit = 2): SupportCollection
    {
        $collections = $this->collections($viewer, $limit);

        if ($collections->isEmpty()) {
            return collect();
        }

        $rows = DB::query()
            ->fromSub(
                Media::query()->unmerged()->visibleTo($viewer)
                    ->join('collection_items', 'collection_items.media_id', '=', 'media.id')
                    ->whereIn('collection_items.collection_id', $collections->modelKeys())
                    ->selectRaw('media.id as media_id, collection_items.collection_id, row_number() over (partition by collection_items.collection_id order by collection_items.position, collection_items.id) as place')
                    ->toBase(),
                'ranked',
            )
            ->where('place', '<=', self::PER_SHELF)
            ->orderBy('collection_id')->orderBy('place')
            ->get()
            ->groupBy('collection_id');

        $media = Media::query()->whereIn('id', $rows->flatten(1)->pluck('media_id')->all())->with(['mediaType', 'publicPrimaryDewey.deweyClass'])->get()->keyBy('id');

        return $collections->map(fn (Collection $collection): Shelf => new Shelf(
            key: 'collection-'.$collection->id,
            title: $collection->name,
            items: collect($rows[$collection->id] ?? [])->map(fn (stdClass $row): ?Media => $media->get($row->media_id))->filter()->values(),
            reasonLabel: __('Staff pick'),
            description: $collection->description,
            href: route('collections.show', ['slug' => $collection->slug, 'editors' => 1]),
        ))->values()->toBase();
    }
}
