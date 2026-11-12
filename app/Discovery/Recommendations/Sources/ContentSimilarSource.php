<?php

namespace App\Discovery\Recommendations\Sources;

use App\Discovery\Recommendations\Candidate;
use App\Enums\ReasonType;
use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Titles close in text to what the reader liked: the neighbors of the seeds from the active `lsa` version (`embedding` neighbors are
 * `SemanticSimilarSource`'s). A neighbor scores its similarity times the strength of the seed it came from, the best seed when several lead
 * to it, and is credited to that seed ("Because you read ...").
 */
class ContentSimilarSource extends Source
{
    public function key(): string
    {
        return 'content_similar';
    }

    protected function gather(User $user, int $limit): Collection
    {
        $seeds = $this->seedsOf($user);
        $best = [];

        foreach ($this->neighborsOf($user, $seeds, 'lsa') as $row) {
            $score = $row->score * $seeds[$row->media_id];

            if ($score > ($best[$row->similar_media_id][0] ?? -1)) {
                $best[$row->similar_media_id] = [$score, $row->media_id];
            }
        }

        return collect($best)
            ->map(fn (array $hit, int $id): Candidate => new Candidate($id, $this->key(), min(1.0, $hit[0]), ReasonType::BecauseYouRead, $hit[1]))
            ->sortBy(fn (Candidate $candidate): array => [-$candidate->score, $candidate->mediaId])
            ->values();
    }

    /**
     * The neighbors of one title the reader chose (their latest favorite for a shelf), strongest first, labelled with it. They come from the
     * active `embedding` version when it has neighbors of that title the reader may be offered, which match on meaning rather than words,
     * and from the active `lsa` version otherwise (so a catalog with no embeddings gets the shelves it always had).
     *
     * @return Collection<int, Candidate>
     */
    public function similarTo(User $user, Media $seed, ReasonType $reason, int $limit): Collection
    {
        $seeds = collect([$seed->id => 1.0]);
        $rows = $this->neighborsOf($user, $seeds, 'embedding');

        return ($rows->isEmpty() ? $this->neighborsOf($user, $seeds, 'lsa') : $rows)
            ->take($limit)
            ->map(fn (stdClass $row): Candidate => new Candidate((int) $row->similar_media_id, $this->key(), min(1.0, (float) $row->score), $reason, $seed->id))
            ->values();
    }
}
