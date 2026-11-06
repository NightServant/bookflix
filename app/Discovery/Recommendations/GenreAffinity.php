<?php

namespace App\Discovery\Recommendations;

use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Facades\DB;

/**
 * How much a reader leans to each genre: the weight of the genres they chose, plus the strength of every seed title carrying the genre.
 * Two sources use it (popular in their genres, and exploration outside them), so the top three are worked out in one place.
 */
class GenreAffinity
{
    public function __construct(private SeedItems $seeds) {}

    /**
     * @return array<int, float> genre id => affinity, strongest first, at most `$count`
     */
    public function top(User $user, int $count = 3): array
    {
        $affinity = [];

        $chosen = UserPreference::query()->where('user_id', $user->id)->where('preferable_type', 'genre')->get(['preferable_id', 'weight']);

        foreach ($chosen as $preference) {
            $affinity[$preference->preferable_id] = ($affinity[$preference->preferable_id] ?? 0.0) + $preference->weight;
        }

        $seeds = $this->seeds->for($user);

        if ($seeds->isNotEmpty()) {
            foreach (DB::table('media_genres')->whereIn('media_id', $seeds->keys()->all())->get(['media_id', 'genre_id']) as $row) {
                $affinity[$row->genre_id] = ($affinity[$row->genre_id] ?? 0.0) + $seeds[$row->media_id];
            }
        }

        uksort($affinity, fn (int $a, int $b): int => [$affinity[$b], $a] <=> [$affinity[$a], $b]);

        return array_slice($affinity, 0, $count, true);
    }
}
