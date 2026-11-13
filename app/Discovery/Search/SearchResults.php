<?php

namespace App\Discovery\Search;

use App\Models\Media;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * A page of ranked search hits. `total()` is the fused list's size, a floor when `hasMore` says a leg stopped at its limit.
 * `throttled` marks the empty page returned when the reader searched too often.
 *
 * `appliedIntent` and `suggestedIntent` split what a QueryIntent named, in the shape of SearchFilters::toArray() (`mediaTypes`, `genres`,
 * `themes`, `deweyCode`): applied values narrowed these results, suggested ones were dropped because they would have left none.
 *
 * `includesSemanticMatches` is true when the semantic leg listed at least one story no other leg found: the results then hold matches by meaning, not only by words.
 *
 * @extends LengthAwarePaginator<int, Media>
 */
class SearchResults extends LengthAwarePaginator
{
    /**
     * @param  Collection<int, Media>  $items
     * @param  array<string, mixed>  $options
     * @param  array<string, list<string>|string>  $appliedIntent
     * @param  array<string, list<string>|string>  $suggestedIntent
     */
    public function __construct(
        $items,
        int $total,
        int $perPage,
        int $currentPage,
        array $options = [],
        public readonly bool $hasMore = false,
        public readonly bool $throttled = false,
        public readonly array $appliedIntent = [],
        public readonly array $suggestedIntent = [],
        public readonly bool $includesSemanticMatches = false,
    ) {
        parent::__construct($items, $total, $perPage, $currentPage, $options);
    }
}
