<?php

namespace App\Reader;

use App\Enums\AccessType;
use App\Enums\ContentStatus;
use App\Models\ContentSource;
use App\Models\User;

/**
 * The one answer to "may this person read this file?". It fails closed: every condition must hold, and anything not named here is a no.
 * Both ContentSourcePolicy::view and every reader route go through it, so the rule is not copied anywhere.
 */
class ContentAccessGate
{
    /**
     * An active reader, a story that is there and not gated for them (the mature gate holds), a file that has arrived, and either a verified
     * public-domain or licensed source (any reader) or an upload verified for its owner (only the owner). A link out is never streamed.
     */
    public function allows(User $user, ContentSource $source): bool
    {
        if ($user->isDeactivated()) {
            return false;
        }

        if ($source->access_type === AccessType::ExternalLink || ! $this->hasFile($source)) {
            return false;
        }

        // The relation leaves out a soft-deleted story, so a removed story ends reading at once.
        $media = $source->media;

        if ($media === null || $media->isGatedFor($user)) {
            return false;
        }

        return match ($source->access_type) {
            AccessType::PublicDomain, AccessType::Licensed => $source->isVerified(),
            AccessType::UserOwned => $source->owner_user_id === $user->id && $source->isVerified(),
        };
    }

    /**
     * An admin checking a file before verifying it: any verification state, but never a reader's own upload (those are private) and only when a
     * file is stored. This opens the file from the admin side only; it never lets an admin read it as a reader.
     */
    public function preview(User $admin, ContentSource $source): bool
    {
        return $admin->is_admin
            && ! $admin->isDeactivated()
            && $source->access_type !== AccessType::UserOwned
            && $source->access_type !== AccessType::ExternalLink
            && $source->storage_path !== null;
    }

    private function hasFile(ContentSource $source): bool
    {
        return $source->status === ContentStatus::Ready && $source->storage_path !== null;
    }
}
