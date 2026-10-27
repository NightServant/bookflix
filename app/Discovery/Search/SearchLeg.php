<?php

namespace App\Discovery\Search;

interface SearchLeg
{
    public function key(): string;

    /**
     * Ids of the public media that match the (already normalized) query and filters, best first.
     *
     * @return list<int>
     */
    public function ids(string $query, SearchFilters $filters, int $limit = 100): array;
}
