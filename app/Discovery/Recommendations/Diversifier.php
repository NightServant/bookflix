<?php

namespace App\Discovery\Recommendations;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reorders a ranked list with maximal marginal relevance, so a reader whose taste is broad does not get ten titles of one genre: the best
 * title first, then repeatedly the one with the highest `λ × score − (1 − λ) × (its largest similarity to a title already picked)`. With
 * λ = 1 that is plain score order. Similarity defaults to the Jaccard overlap of genres and themes, loaded once for the whole list.
 */
class Diversifier
{
    /**
     * @param  Collection<int, RankedItem>  $ranked
     * @param  (Closure(int, int): float)|null  $similarity  media id, media id => similarity in [0, 1]
     * @return Collection<int, RankedItem>
     */
    public function rerank(Collection $ranked, float $lambda = 0.7, int $limit = 20, ?Closure $similarity = null): Collection
    {
        $remaining = $ranked->sortBy(fn (RankedItem $item): array => [-$item->score, $item->mediaId])->values()->all();
        $picked = [];

        if ($remaining === [] || $limit < 1) {
            return new Collection;
        }

        $similarity ??= $this->tagSimilarity(array_map(fn (RankedItem $item): int => $item->mediaId, $remaining));
        $closest = array_fill(0, count($remaining), 0.0);

        while ($remaining !== [] && count($picked) < $limit) {
            $bestIndex = 0;
            $bestValue = -INF;

            foreach ($remaining as $index => $item) {
                $value = $lambda * $item->score - (1 - $lambda) * $closest[$index];

                if ($value > $bestValue) {
                    $bestValue = $value;
                    $bestIndex = $index;
                }
            }

            $chosen = $remaining[$bestIndex];
            $picked[] = $chosen;
            unset($remaining[$bestIndex]);

            foreach ($remaining as $index => $item) {
                $closest[$index] = max($closest[$index], $similarity($item->mediaId, $chosen->mediaId));
            }
        }

        return new Collection($picked);
    }

    /**
     * Genre and theme Jaccard similarity of two media, from one query for all of them.
     *
     * @param  list<int>  $ids
     * @return Closure(int, int): float
     */
    private function tagSimilarity(array $ids): Closure
    {
        $sets = DB::table('media_genres')->whereIn('media_id', $ids)->select('media_id', DB::raw("'g' || genre_id as tag"))
            ->unionAll(DB::table('media_themes')->whereIn('media_id', $ids)->select('media_id', DB::raw("'t' || theme_id as tag")))
            ->get()
            ->groupBy('media_id')
            ->map(fn (Collection $rows): array => $rows->pluck('tag')->flip()->all())
            ->all();

        return function (int $a, int $b) use ($sets): float {
            $left = $sets[$a] ?? [];
            $right = $sets[$b] ?? [];
            $shared = count(array_intersect_key($left, $right));
            $union = count($left) + count($right) - $shared;

            return $union === 0 ? 0.0 : $shared / $union;
        };
    }
}
