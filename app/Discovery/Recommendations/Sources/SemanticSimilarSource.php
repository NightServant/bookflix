<?php

namespace App\Discovery\Recommendations\Sources;

use App\Discovery\Recommendations\Candidate;
use App\Enums\ReasonType;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Titles close in meaning to what the reader liked: the neighbors of the seeds from the active `embedding` version, which catch shared themes
 * that use different words. Scored as `ContentSimilarSource` scores its `lsa` neighbors and credited to the best seed ("Similar themes to ...").
 * With no active embedding version, or none of its rows for the seeds, it offers nothing and the other sources carry the shelves.
 */
class SemanticSimilarSource extends Source
{
    public function key(): string
    {
        return 'semantic_similar';
    }

    protected function gather(User $user, int $limit): Collection
    {
        $seeds = $this->seedsOf($user);
        $best = [];

        foreach ($this->neighborsOf($user, $seeds, 'embedding') as $row) {
            $score = $row->score * $seeds[$row->media_id];

            if ($score > ($best[$row->similar_media_id][0] ?? -1)) {
                $best[$row->similar_media_id] = [$score, $row->media_id];
            }
        }

        return collect($best)
            ->map(fn (array $hit, int $id): Candidate => new Candidate($id, $this->key(), min(1.0, $hit[0]), ReasonType::SimilarThemes, $hit[1]))
            ->sortBy(fn (Candidate $candidate): array => [-$candidate->score, $candidate->mediaId])
            ->values();
    }
}
