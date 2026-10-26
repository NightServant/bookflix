<?php

namespace App\Discovery;

use App\Models\DeweyClass;
use App\Models\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The stories "like" another one, until Phase 6 swaps this for the learned `media_similarities`.
 */
class SimilarMedia
{
    /**
     * The same series first (in reading order), then the stories sharing the most genres and themes, then the rest of the
     * same primary-class division, most rated first. Never the story itself; merged and deleted stories are skipped.
     * Each comes with the `mediaType` and `publicPrimaryDewey.deweyClass` a cover tile, preview or quick view reads.
     *
     * @return Collection<int, Media>
     */
    public function for(Media $media, int $limit = 12): Collection
    {
        $ids = [];

        foreach ([$this->siblings(...), $this->sharedTaxonomy(...), $this->sameDivision(...)] as $tier) {
            $ids = array_slice(array_values(array_unique([...$ids, ...$tier($media, $limit)])), 0, $limit);

            if (count($ids) >= $limit) {
                break;
            }
        }

        if ($ids === []) {
            return new Collection;
        }

        $found = Media::query()->whereIn('id', $ids)->with(['mediaType', 'publicPrimaryDewey.deweyClass'])->get()->keyBy('id');

        return new Collection(array_values(array_filter(array_map(fn (int $id): ?Media => $found->get($id), $ids))));
    }

    /**
     * @return list<int>
     */
    private function siblings(Media $media, int $limit): array
    {
        return $this->ids(Media::query()->unmerged()
            ->join('media_series', 'media_series.media_id', '=', 'media.id')
            ->whereIn('media_series.series_id', DB::table('media_series')->where('media_id', $media->id)->select('series_id'))
            ->where('media.id', '!=', $media->id)
            ->orderByRaw('media_series.position asc nulls last')
            ->orderBy('media.title'), $limit);
    }

    /**
     * @return list<int>
     */
    private function sharedTaxonomy(Media $media, int $limit): array
    {
        $genres = DB::table('media_genres')->select('media_id')
            ->whereIn('genre_id', DB::table('media_genres')->where('media_id', $media->id)->select('genre_id'));
        $themes = DB::table('media_themes')->select('media_id')
            ->whereIn('theme_id', DB::table('media_themes')->where('media_id', $media->id)->select('theme_id'));

        $shared = DB::query()->fromSub($genres->unionAll($themes), 'taxonomy')
            ->selectRaw('media_id, count(*) as score')
            ->groupBy('media_id');

        return $this->ids(Media::query()->unmerged()
            ->joinSub($shared, 'shared', 'shared.media_id', '=', 'media.id')
            ->where('media.id', '!=', $media->id)
            ->orderByDesc('shared.score')
            ->orderByDesc('media.ratings_count')
            ->orderBy('media.id'), $limit);
    }

    /**
     * The division is the second level of the primary class (810 for 813); a story classified at the top level uses that level.
     *
     * @return list<int>
     */
    private function sameDivision(Media $media, int $limit): array
    {
        $path = $media->loadMissing('publicPrimaryDewey.deweyClass')->publicPrimaryDewey?->deweyClass?->path;

        if ($path === null) {
            return [];
        }

        $division = DeweyClass::where('code', explode('/', $path)[1] ?? explode('/', $path)[0])->first();

        if ($division === null) {
            return [];
        }

        return $this->ids(Media::query()->unmerged()->inDeweySubtree($division)
            ->where('media.id', '!=', $media->id)
            ->orderByDesc('media.ratings_count')
            ->orderBy('media.id'), $limit);
    }

    /**
     * The ids of the first `$limit` rows of an already ordered media query.
     *
     * @param  Builder<Media>  $query
     * @return list<int>
     */
    private function ids(Builder $query, int $limit): array
    {
        return array_values(array_map(intval(...), $query->limit($limit)->pluck('media.id')->all()));
    }
}
