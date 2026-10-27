<?php

namespace App\Discovery\Search;

use App\Models\SearchQuery;
use App\Models\User;
use Illuminate\Support\Str;
use Transliterator;

class SearchLogger
{
    /** The width of the `query` and `normalized` columns. */
    private const MAX_LENGTH = 255;

    /**
     * Record one settled search. A reader who opted out of personalization is logged without their id; the session is only ever stored as a keyed hash.
     */
    public function log(string $query, SearchFilters $filters, int $resultsCount, ?User $user, ?int $previousQueryId): SearchQuery
    {
        $query = mb_substr($query, 0, self::MAX_LENGTH);

        return SearchQuery::create([
            'user_id' => $user?->personalizationEnabled() ? $user->id : null,
            'session_hash' => hash_hmac('sha256', session()->getId(), config('app.key')),
            'query' => $query,
            'normalized' => mb_substr($this->normalize($query), 0, self::MAX_LENGTH),
            'filters' => $filters->toArray(),
            'results_count' => $resultsCount,
            'previous_query_id' => $previousQueryId,
        ]);
    }

    /**
     * Lowercased, accent-stripped and single-spaced, so `  Pokémon  Go` and `pokemon go` are one query.
     */
    public function normalize(string $query): string
    {
        $folded = Transliterator::create('NFC; [:Latin:] Latin-ASCII')?->transliterate($query) ?: $query;

        return trim(preg_replace('/\s+/u', ' ', Str::lower($folded)) ?? '');
    }
}
