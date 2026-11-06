<?php

namespace App\Discovery\Recommendations;

use Illuminate\Support\Collection;
use Random\Randomizer;

/**
 * Gives a list its exploration slots: every `$every`-th position (the 10th, 20th, ...) is taken by the next exploration item not already in the
 * list, so about one title in ten is something outside what the ranking would pick (ε = 0.10 as a fixed slot, with the randomness in the pool).
 * The list never grows and no title appears twice. A slot the pool cannot fill keeps its ranked item.
 */
class ExplorationInjector
{
    /**
     * @param  Collection<int, RankedItem>  $ranked
     * @param  Collection<int, RankedItem>  $exploration
     * @param  Randomizer|null  $randomizer  shuffles the pool before it is used; the order given is kept without one
     * @return Collection<int, RankedItem>
     */
    public function inject(Collection $ranked, Collection $exploration, int $every = 10, ?Randomizer $randomizer = null): Collection
    {
        if ($every < 1) {
            return $ranked->values();
        }

        $items = $ranked->values()->all();
        $taken = array_fill_keys(array_map(fn (RankedItem $item): int => $item->mediaId, $items), true);
        $pool = $exploration->values()->all();

        if ($randomizer !== null) {
            $pool = $randomizer->shuffleArray($pool);
        }

        $next = 0;

        for ($slot = $every - 1; $slot < count($items); $slot += $every) {
            while (isset($pool[$next]) && isset($taken[$pool[$next]->mediaId])) {
                $next++;
            }

            if (! isset($pool[$next])) {
                break;
            }

            unset($taken[$items[$slot]->mediaId]);
            $items[$slot] = $pool[$next];
            $taken[$pool[$next]->mediaId] = true;
            $next++;
        }

        return new Collection($items);
    }
}
