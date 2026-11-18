<?php

namespace App\Social;

use App\Models\Collection;
use App\Models\Profile;
use App\Models\User;

/**
 * Resolve /u/{handle}/collections/{slug} to the collection, or null when there is none the viewer may view (unknown handle, private, moderator-hidden,
 * or owned by an admin or deactivated reader), so callers answer null with 404 and every case looks the same. A public collection stays viewable when its
 * owner's profile is private (P10-R12), so this does not ask `view-profile`.
 */
class CollectionLookup
{
    public function find(User $viewer, string $handle, string $slug): ?Collection
    {
        $ownerId = Profile::query()->where('handle', mb_strtolower(trim($handle)))->value('user_id');

        if ($ownerId === null) {
            return null;
        }

        $collection = Collection::resolveFor($slug, $viewer, owner: (int) $ownerId);

        return $collection !== null && $viewer->can('view', $collection) ? $collection : null;
    }
}
