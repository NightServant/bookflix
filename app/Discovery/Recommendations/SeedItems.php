<?php

namespace App\Discovery\Recommendations;

use App\Enums\ReadingStatus;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\Rating;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;

/**
 * The titles a reader's taste is read from, with how much each counts. A favorite is 1.0, a rating counts as score / 10, a completed story 0.8
 * and one being read 0.5; the strongest applies. An abandoned story and one rated 4 or less is no seed at all, whatever else is true of it,
 * and a title only in "want to read" or "paused" says too little. The reader's own library is read as it is: the mature gate applies to what
 * is proposed, not to the reader's own shelf.
 */
class SeedItems
{
    /** Ratings from this score up count as a liking. */
    private const LIKED_FROM = 5;

    /** Ratings from this score up make a story the reader's latest favorite for an explanation. */
    private const LOVED_FROM = 8;

    /** The seeds of the build in progress, keyed by reader; null outside `during`, so nothing outlives a build. */
    private ?array $memo = null;

    /**
     * Runs one build with the seeds read once per reader: the sources, the ranker and the shelf builder all ask for them. The memo is dropped
     * when the build ends, even by an exception, so a later change to the library is always seen.
     *
     * @template T
     *
     * @param  Closure(): T  $build
     * @return T
     */
    public function during(Closure $build): mixed
    {
        $outer = $this->memo;
        $this->memo ??= [];

        try {
            return $build();
        } finally {
            $this->memo = $outer;
        }
    }

    /**
     * @return Collection<int, float> media id => strength, strongest first
     */
    public function for(User $user): Collection
    {
        return $this->memo === null ? $this->read($user) : $this->memo[$user->id] ??= $this->read($user);
    }

    /**
     * @return Collection<int, float>
     */
    private function read(User $user): Collection
    {
        $strengths = [];
        $excluded = [];

        $entries = LibraryEntry::query()->where('user_id', $user->id)->get(['media_id', 'status', 'favorited_at']);

        foreach ($entries as $entry) {
            if ($entry->status === ReadingStatus::Abandoned) {
                $excluded[$entry->media_id] = true;
            }

            $strength = match (true) {
                $entry->favorited_at !== null => 1.0,
                $entry->status === ReadingStatus::Completed => 0.8,
                $entry->status === ReadingStatus::Reading => 0.5,
                default => 0.0,
            };

            $strengths[$entry->media_id] = max($strengths[$entry->media_id] ?? 0.0, $strength);
        }

        foreach (Rating::query()->where('user_id', $user->id)->get(['media_id', 'score']) as $rating) {
            if ($rating->score < self::LIKED_FROM) {
                $excluded[$rating->media_id] = true;

                continue;
            }

            $strengths[$rating->media_id] = max($strengths[$rating->media_id] ?? 0.0, $rating->score / 10.0);
        }

        $live = Media::query()->unmerged()->whereIn('media.id', array_keys($strengths))->pluck('media.id')->flip();

        return collect($strengths)
            ->filter(fn (float $strength, int $id): bool => $strength > 0 && ! isset($excluded[$id]) && $live->has($id))
            ->sortByDesc(fn (float $strength, int $id): array => [$strength, -$id])
            ->toBase();
    }

    /**
     * The reader's latest favorite: the story they completed last, or rated 8 or more last, whichever is newer. Null when there is none.
     */
    public function mostRecent(User $user): ?Media
    {
        $completed = LibraryEntry::query()->where('user_id', $user->id)
            ->where('status', ReadingStatus::Completed)->whereNotNull('completed_at')
            ->orderByDesc('completed_at')->first(['media_id', 'completed_at']);

        $rated = Rating::query()->where('user_id', $user->id)->where('score', '>=', self::LOVED_FROM)
            ->orderByDesc('updated_at')->first(['media_id', 'updated_at']);

        $latest = $completed !== null && $rated !== null
            ? ($completed->completed_at >= $rated->updated_at ? $completed->media_id : $rated->media_id)
            : ($completed ?? $rated)?->media_id;

        return $latest === null ? null : Media::query()->unmerged()->find($latest);
    }
}
