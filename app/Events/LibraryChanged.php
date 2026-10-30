<?php

namespace App\Events;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A reader's library or ratings changed for one story. The actions in App\Actions\Library fire it after every write; Phase 6 hangs
 * the recommendation cache invalidation on it.
 */
final class LibraryChanged
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly Media $media,
    ) {}
}
