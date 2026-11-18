<?php

namespace App\Social;

use Illuminate\Support\Collection;

/**
 * A page of the feed and the opaque cursor of the next one (null on the last page).
 */
final readonly class ActivityPage
{
    /**
     * @param  Collection<int, ActivityEvent>  $events
     */
    public function __construct(public Collection $events, public ?string $nextCursor) {}

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }
}
