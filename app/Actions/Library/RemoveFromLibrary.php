<?php

namespace App\Actions\Library;

use App\Events\LibraryChanged;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\User;

/**
 * Take a story out of a reader's library. The favorite goes with it, since a favorite is the same row.
 */
class RemoveFromLibrary
{
    public function handle(User $user, Media $media): void
    {
        $removed = LibraryEntry::where('user_id', $user->id)->where('media_id', $media->id)->delete();

        if ($removed > 0) {
            LibraryChanged::dispatch($user, $media);
        }
    }
}
