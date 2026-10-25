<?php

namespace App\Discovery\Search;

use App\Models\Media;
use Illuminate\Database\Eloquent\Builder;

final readonly class SearchFilters
{
    /**
     * Narrow a media query; no filter is defined yet.
     *
     * @param  Builder<Media>  $query
     * @return Builder<Media>
     */
    public function apply(Builder $query): Builder
    {
        return $query;
    }
}
