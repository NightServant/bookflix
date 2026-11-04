<?php

namespace App\Discovery\Recommendations;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One way of finding titles for a reader. A source returns ids and scores (never models), already limited to what the reader may see and
 * has not met: the ranker (6.5) blends the scores of every source.
 */
interface CandidateSource
{
    /** The stable name stored with a candidate: `content_similar`, `collaborative`, `dewey_similar`, `trending`, ... */
    public function key(): string;

    /**
     * Up to `$limit` candidates, best first.
     *
     * @return Collection<int, Candidate>
     */
    public function candidatesFor(User $user, int $limit): Collection;
}
