<?php

namespace App\Discovery\Search;

use App\Discovery\Search\Legs\KeywordLeg;
use App\Discovery\Search\Legs\SubjectLeg;
use App\Models\Media;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class HybridSearch
{
    public const MAX_QUERY_LENGTH = 200;

    /** Each leg's ranking ceiling; a leg that reaches it has more hits than it listed. */
    public const LEG_LIMIT = 100;

    public function __construct(private KeywordLeg $keyword, private SubjectLeg $subject) {}

    /**
     * Every leg's hits, fused by reciprocal rank, one page of media in rank order. A blank or punctuation-only query runs nothing.
     *
     * @return SearchResults `hasMore` is true when a leg returned as many ids as it may, so the total is a floor
     */
    public function search(string $query, SearchFilters $filters, ?User $user, int $perPage = 24): SearchResults
    {
        $page = Paginator::resolveCurrentPage();
        $query = $this->normalize($query);

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
     * No text, only filters (Discover): the most rated first, newest breaking ties.
     *
     * @return LengthAwarePaginator<int, Media>
     */
    public function browse(SearchFilters $filters, int $perPage = 24): LengthAwarePaginator
    {
        return $filters->apply(Media::query()->unmerged())
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
