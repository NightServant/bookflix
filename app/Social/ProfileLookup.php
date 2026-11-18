<?php

namespace App\Social;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Resolve a public handle to the reader it belongs to, or null when there is none or the viewer may not see it. Callers answer null with 404,
 * so an unknown, retired, private, hidden, admin or deactivated handle all look the same. The profile is loaded with the user.
 */
class ProfileLookup
{
    public function find(?User $viewer, string $handle): ?User
    {
        $profile = Profile::query()->where('handle', mb_strtolower(trim($handle)))->first();

        if ($profile === null) {
            return null;
        }

        $owner = User::query()->find($profile->user_id);

        if ($owner === null) {
            return null;
        }

        $owner->setRelation('profile', $profile);

        return Gate::forUser($viewer)->allows('view-profile', $owner) ? $owner : null;
    }
}
