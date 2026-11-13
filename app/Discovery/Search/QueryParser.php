<?php

namespace App\Discovery\Search;

use App\Models\User;

/**
 * Turns a free-text search into taxonomy filters (QueryIntent). Rule based for now; Phase 11 can put a language model behind the same contract.
 */
interface QueryParser
{
    /**
     * Never throws, whatever the text. The reader decides what can be named: without mature access (a guest is null) no mature genre is ever parsed.
     */
    public function parse(string $query, ?User $viewer = null): QueryIntent;
}
