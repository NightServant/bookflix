<?php

namespace App\Actions\Reader;

use App\Enums\AccessType;
use App\Models\ContentSource;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Removes a reader's own upload. Only its owner may, and only for a user-owned source: public files are an admin's business. Deleting the model
 * removes the file too (its `deleting` hook), and the reader's place survives because `reading_progress.content_source_id` nulls on delete.
 */
class DeleteContentSource
{
    /**
     * @throws AuthorizationException
     */
    public function handle(User $user, ContentSource $source): void
    {
        if ($source->access_type !== AccessType::UserOwned || $source->owner_user_id !== $user->id) {
            throw new AuthorizationException(__('You can only remove your own files.'));
        }

        $source->delete();
    }
}
