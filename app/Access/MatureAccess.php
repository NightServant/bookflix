<?php

namespace App\Access;

use App\Models\User;

/**
 * Who may see mature and adult titles: a signed-in reader who turned the preference on and confirmed they are 18 or older.
 * Everyone else is gated, guests always. This is an access rule, not a filter: it is applied by the query layer (see Media::scopeVisibleTo),
 * so no URL, filter or Livewire payload can lift it.
 */
final class MatureAccess
{
    public static function allows(?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        $profile = $viewer->profile;

        return $profile->show_mature && $profile->adult_confirmed_at !== null;
    }

    /**
     * The key part cached lists carry, so a gated reader and an opted-in one never share a cached page of ids or counts.
     */
    public static function cacheKey(?User $viewer): string
    {
        return self::allows($viewer) ? 'mature' : 'gated';
    }
}
