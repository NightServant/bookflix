<?php

namespace App\Listeners;

use App\Access\MatureAccess;
use App\Events\HomePreferencesChanged;
use App\Events\LibraryChanged;
use App\Events\RecommendationDismissed;
use Illuminate\Support\Facades\Cache;

/**
 * Forgets a reader's cached home shelves when what they were built from changes: the library or a rating, a dismissal, or the mature-access
 * or personalization setting. Both access variants go, so neither is served stale.
 */
class ForgetHomeShelves
{
    public function handle(LibraryChanged|RecommendationDismissed|HomePreferencesChanged $event): void
    {
        $userId = $event instanceof LibraryChanged ? $event->user->id : $event->userId;

        foreach (MatureAccess::CACHE_KEYS as $access) {
            Cache::forget("home:shelves:{$userId}:{$access}");
        }
    }
}
