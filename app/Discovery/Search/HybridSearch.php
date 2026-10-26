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
    private const MAX_QUERY_LENGTH = 200;

    public function __construct(private KeywordLeg $keyword, private SubjectLeg $subject) {}

    /**
     * Every leg's hits, fused by reciprocal rank, one page of media in rank order. A blank or punctuation-only query runs nothing.
     *
     * @return LengthAwarePaginator<int, Media>
     */
    public function search(string $query, SearchFilters $filters, ?User $user, int $perPage = 24): LengthAwarePaginator
    {
        $page = Paginator::resolveCurrentPage();
        $query = $this->normalize($query);

        $ranked = $query === '' ? [] : array_keys(Rrf::fuse(array_map(
            fn (SearchLeg $leg): array => $leg->ids($query, $filters),
            [$this->keyword, $this->subject],
        )));

        $pageIds = array_slice($ranked, ($page - 1) * $perPage, $perPage);
        $media = $pageIds === [] ? collect() : Media::query()->whereIn('id', $pageIds)->get()->keyBy('id');

        return new LengthAwarePaginator(
            collect($pageIds)->map(fn (int $id) => $media->get($id))->filter()->values(),
            count($ranked),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
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
