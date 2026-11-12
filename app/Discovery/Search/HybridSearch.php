<?php

namespace App\Discovery\Search;

use App\Discovery\Search\Legs\KeywordLeg;
use App\Discovery\Search\Legs\SubjectLeg;
use App\Models\Media;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;

class HybridSearch
{
    public const MAX_QUERY_LENGTH = 200;

    /** Each leg's ranking ceiling; a leg that reaches it has more hits than it listed. */
    public const LEG_LIMIT = 100;

    /** Text searches allowed per reader per minute; each one runs several queries. */
    public const PER_MINUTE = 120;

    /** The intent groups in the order they are kept: relaxation drops the last one first, since a type says most about what the reader wants. */
    private const INTENT_GROUPS = ['mediaTypes' => 'type', 'genres' => 'genre', 'themes' => 'theme', 'deweyCode' => 'dewey'];

    public function __construct(private KeywordLeg $keyword, private SubjectLeg $subject, private QueryVocabulary $vocabulary) {}

    /**
     * Every leg's hits, fused by reciprocal rank, one page of media in rank order. A blank or punctuation-only query runs nothing.
     * The filters narrow every leg in SQL (see SearchFilters): a genre family includes its subgenres, a group's values are any-of, groups are all-of,
     * and excluded warnings drop only works where the warning is present or disputed.
     * Mature and adult titles are left out of every leg unless the reader opted in (see MatureAccess); a guest never sees them.
     * Past 120 searches a minute the page comes back empty and `throttled`, without touching the database.
     *
     * An intent (see QueryParser) adds its types, genres, themes and Dewey code as filters, but only while at least one result remains: when they
     * would leave none, the Dewey code, then the themes, then the genres, then the types are dropped until some result does, and what was dropped
     * is reported as suggested rather than applied. A group the reader filtered by themselves is theirs and the intent's is ignored, as is any
     * value outside the reader's vocabulary (a mature genre for a gated reader). The keyword leg still reads the reader's own words.
     *
     * @return SearchResults `hasMore` is true when a leg returned as many ids as it may, so the total is a floor
     */
    public function search(string $query, SearchFilters $filters, ?User $user, int $perPage = 24, ?QueryIntent $intent = null): SearchResults
    {
        $page = Paginator::resolveCurrentPage();
        $query = $this->normalize($query);

        $throttleKey = 'search:'.($user === null ? request()->ip() : $user->id);

        if ($query !== '' && RateLimiter::tooManyAttempts($throttleKey, self::PER_MINUTE)) {
            return new SearchResults(collect(), 0, $perPage, $page, ['path' => Paginator::resolveCurrentPath()], throttled: true);
        }

        $query === '' || RateLimiter::hit($throttleKey, 60);

        // The access gate is applied here, to every leg, from who is asking; the filters cannot widen it.
        $filters = $filters->forViewer($user);

        $groups = $query === '' || $intent === null ? [] : $this->intentGroups($intent, $filters, $user);
        [$lists, $applied] = $query === '' ? [[], []] : $this->relaxed($query, $filters, $groups);
        $ranked = array_keys(Rrf::fuse($lists));
        $hasMore = collect($lists)->contains(fn (array $ids): bool => count($ids) >= self::LEG_LIMIT);

        $pageIds = array_slice($ranked, ($page - 1) * $perPage, $perPage);
        $media = $pageIds === [] ? collect() : Media::query()->whereIn('id', $pageIds)->get()->keyBy('id');

        return new SearchResults(
            collect($pageIds)->map(fn (int $id) => $media->get($id))->filter()->values(),
            count($ranked),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
            $hasMore,
            appliedIntent: $applied,
            suggestedIntent: array_diff_key($groups, $applied),
        );
    }

    /**
     * The intent's filter values this search may use, keyed like SearchFilters, in INTENT_GROUPS order: none for a group the reader set,
     * and none outside the reader's vocabulary.
     *
     * @return array<string, list<string>|string>
     */
    private function intentGroups(QueryIntent $intent, SearchFilters $filters, ?User $user): array
    {
        $known = $this->vocabulary->slugs($user);
        $groups = [];

        foreach (self::INTENT_GROUPS as $group => $kind) {
            if ($filters->{$group} !== [] && $filters->{$group} !== null) {
                continue;
            }

            $values = array_values(array_intersect((array) $intent->{$group}, $known[$kind]));

            if ($values !== []) {
                $groups[$group] = $group === 'deweyCode' ? $values[0] : $values;
            }
        }

        return $groups;
    }

    /**
     * Every leg's ids with as many intent groups applied as still leave a result, and the groups that were.
     * One run when the whole intent fits, two when even the reader's own filters find nothing, at most one more per dropped group otherwise.
     *
     * @param  array<string, list<string>|string>  $groups
     * @return array{0: list<list<int>>, 1: array<string, list<string>|string>}
     */
    private function relaxed(string $query, SearchFilters $filters, array $groups): array
    {
        $run = fn (array $applied): array => array_map(
            fn (SearchLeg $leg): array => $leg->ids($query, new SearchFilters(...[...get_object_vars($filters), ...$applied]), self::LEG_LIMIT),
            [$this->keyword, $this->subject],
        );
        $found = fn (array $lists): bool => collect($lists)->contains(fn (array $ids): bool => $ids !== []);

        $lists = $run($groups);

        if ($groups === [] || $found($lists)) {
            return [$lists, $groups];
        }

        $plain = $run([]);

        while ($found($plain) && count($groups) > 1) {
            array_pop($groups);
            $lists = $run($groups);

            if ($found($lists)) {
                return [$lists, $groups];
            }
        }

        return [$plain, []];
    }

    /**
     * No text, only filters (Discover, and the Search page's filters alone): the most rated first, newest breaking ties.
     * The reader is who asks: without one (null) the access gate is shut, as for a guest.
     *
     * @return LengthAwarePaginator<int, Media>
     */
    public function browse(SearchFilters $filters, int $perPage = 24, ?User $viewer = null): LengthAwarePaginator
    {
        return $filters->forViewer($viewer)->apply(Media::query()->unmerged())
            ->orderByDesc('media.ratings_count')
            ->orderByRaw('media.published_on desc nulls last')
            ->orderByDesc('media.id')
            ->paginate($perPage);
    }

    /**
     * Trim, collapse whitespace and cap the length. Text with no letter or digit in it becomes empty.
     */
    private function normalize(string $query): string
    {
        $query = mb_substr(trim(preg_replace('/\s+/u', ' ', $query) ?? ''), 0, self::MAX_QUERY_LENGTH);

        return preg_match('/[\p{L}\p{N}]/u', $query) ? $query : '';
    }
}
