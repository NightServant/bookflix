<?php

namespace App\Discovery\Recommendations;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every tagged source's candidates for one reader, as one list with each title once. The sources do not know each other, so the pool keeps the
 * best score per title (with that source's reason) and drops what the reader already has or dismissed, even though each source filters too.
 * A reader who opted out of personalization gets only the sources that do not read their behavior.
 */
class CandidatePool
{
    /** The sources that read the reader's own history or taste; they are skipped for a reader who opted out. */
    public const PERSONALIZED = ['content_similar', 'collaborative', 'dewey_similar', 'semantic_similar', 'popular_in_genres', 'exploration'];

    /**
     * @param  iterable<CandidateSource>  $sources
     */
    public function __construct(private iterable $sources) {}

    /**
     * @return Collection<int, Candidate>
     */
    public function gather(User $user, int $limit = 200): Collection
    {
        return $this->combine($user, $this->bySource($user, $limit))->take($limit)->values();
    }

    /**
     * What each source proposes, best first, under the source's key; only the sources this reader may be served.
     *
     * @return array<string, Collection<int, Candidate>>
     */
    public function bySource(User $user, int $limit = 200): array
    {
        $enabled = $user->personalizationEnabled();
        $lists = [];

        foreach ($this->sources as $source) {
            if (! $enabled && in_array($source->key(), self::PERSONALIZED, true)) {
                continue;
            }

            $lists[$source->key()] = $source->candidatesFor($user, $limit);
        }

        return $lists;
    }

    /**
     * The lists of `bySource` as one: a title once, at its best score, without the reader's library or dismissed titles.
     *
     * @param  array<string, Collection<int, Candidate>>  $lists
     * @return Collection<int, Candidate> best first, ties by media id
     */
    public function combine(User $user, array $lists): Collection
    {
        $best = [];

        foreach ($lists as $candidates) {
            foreach ($candidates as $candidate) {
                if (! isset($best[$candidate->mediaId]) || $candidate->score > $best[$candidate->mediaId]->score) {
                    $best[$candidate->mediaId] = $candidate;
                }
            }
        }

        if ($best === []) {
            return new Collection;
        }

        $ids = array_keys($best);
        $owned = DB::table('user_library')->where('user_id', $user->id)->whereIn('media_id', $ids)->pluck('media_id')
            ->merge(DB::table('recommendations')
                ->join('recommendation_events', 'recommendation_events.recommendation_id', '=', 'recommendations.id')
                ->where('recommendations.user_id', $user->id)->where('recommendation_events.event', 'dismiss')
                ->whereIn('recommendations.media_id', $ids)->pluck('recommendations.media_id'))
            ->flip();

        return collect($best)
            ->reject(fn (Candidate $candidate): bool => $owned->has($candidate->mediaId))
            ->sortBy(fn (Candidate $candidate): array => [-$candidate->score, $candidate->mediaId])
            ->values();
    }
}
