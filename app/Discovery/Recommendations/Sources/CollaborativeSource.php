<?php

namespace App\Discovery\Recommendations\Sources;

use App\Discovery\Recommendations\Candidate;
use App\Enums\ReasonType;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Titles that readers of the seeds also read: the neighbors from the active `item_cf` version. Several seeds leading to the same title add
 * up (capped at 1), and the title is credited to the seed that contributed most.
 */
class CollaborativeSource extends Source
{
    public function key(): string
    {
        return 'collaborative';
    }

    protected function gather(User $user, int $limit): Collection
    {
        $seeds = $this->seedsOf($user);
        $total = [];
        $top = [];

        foreach ($this->neighborsOf($user, $seeds, 'item_cf') as $row) {
            $part = $row->score * $seeds[$row->media_id];
            $total[$row->similar_media_id] = ($total[$row->similar_media_id] ?? 0.0) + $part;

            if ($part > ($top[$row->similar_media_id][0] ?? -1)) {
                $top[$row->similar_media_id] = [$part, $row->media_id];
            }
        }

        return collect($total)
            ->map(fn (float $sum, int $id): Candidate => new Candidate($id, $this->key(), min(1.0, $sum), ReasonType::PopularWithSimilarReaders, $top[$id][1]))
            ->sortBy(fn (Candidate $candidate): array => [-$candidate->score, $candidate->mediaId])
            ->values();
    }
}
