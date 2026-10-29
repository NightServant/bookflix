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

    public function __construct(private KeywordLeg $keyword, private SubjectLeg $subject) {}

    /**
     * Every leg's hits, fused by reciprocal rank, one page of media in rank order. A blank or punctuation-only query runs nothing.
     * The filters narrow every leg in SQL (see SearchFilters): a genre family includes its subgenres, a group's values are any-of, groups are all-of,
     * and excluded warnings drop only works where the warning is present or disputed.
     * Mature and adult titles are left out of every leg unless the reader opted in (see MatureAccess); a guest never sees them.
     * Past 120 searches a minute the page comes back empty and `throttled`, without touching the database.
     *
     * @return SearchResults `hasMore` is true when a leg returned as many ids as it may, so the total is a floor
     */
    public function search(string $query, SearchFilters $filters, ?User $user, int $perPage = 24): SearchResults
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

        $lists = $query === '' ? [] : array_map(
            fn (SearchLeg $leg): array => $leg->ids($query, $filters, self::LEG_LIMIT),
            [$this->keyword, $this->subject],
        );
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
        );
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
