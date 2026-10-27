<?php

namespace App\Discovery\Search;

use App\Models\Media;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * A page of ranked search hits. `total()` is the fused list's size, a floor when `hasMore` says a leg stopped at its limit.
 *
 * @extends LengthAwarePaginator<int, Media>
 */
class SearchResults extends LengthAwarePaginator
{
    /**
     * @param  Collection<int, Media>  $items
     * @param  array<string, mixed>  $options
     */
    public function __construct($items, int $total, int $perPage, int $currentPage, array $options = [], public readonly bool $hasMore = false)
    {
        parent::__construct($items, $total, $perPage, $currentPage, $options);
    }
}
