<?php

namespace App\Policies;

use App\Models\Collection;
use App\Models\User;

/**
 * Who may do what with a collection. A collection is private to its owner (P10-R3); an editorial one may be viewed by every reader while its owner
 * is active. Only the owner changes a collection.
 */
class CollectionPolicy
{
    public function view(?User $viewer, Collection $collection): bool
    {
        if ($viewer?->id === $collection->user_id) {
            return true;
        }

        return $collection->is_editorial && ! $collection->loadMissing('user')->user->isDeactivated();
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
