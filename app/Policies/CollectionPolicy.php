<?php

namespace App\Policies;

use App\Enums\Visibility;
use App\Models\Collection;
use App\Models\User;

/**
 * Who may do what with a collection. Anyone may view an editorial one, and a public one while its owner is an active reader and moderators have not hidden it; only the owner changes
 * a collection, whatever its visibility (an admin marks one editorial through the page's own check, not here).
 */
class CollectionPolicy
{
    public function view(?User $viewer, Collection $collection): bool
    {
        if ($viewer?->id === $collection->user_id || $collection->is_editorial) {
            return true;
        }

        if ($collection->visibility !== Visibility::Public || $collection->moderation_hidden_at !== null) {
            return false;
        }

        $owner = $collection->loadMissing('user')->user;

        return ! $owner->isDeactivated() && ! $owner->is_admin;
    }

    public function update(User $user, Collection $collection): bool
    {
        return $user->id === $collection->user_id;
    }

    public function delete(User $user, Collection $collection): bool
    {
        return $this->update($user, $collection);
    }

    public function addItem(User $user, Collection $collection): bool
    {
        return $this->update($user, $collection);
    }
}
