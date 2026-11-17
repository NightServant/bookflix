<?php

namespace App\Actions\Reader;

use App\Enums\AccessType;
use App\Models\ContentSource;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * An admin vouches that Bookflix may offer this source: they record the license text and the rights holder, and stamp themselves and the time.
 * A reader's own upload is never verified here (it is private and verified for its owner by the upload).
 */
class VerifyContentSource
{
    /**
     * @throws ValidationException
     */
    public function handle(ContentSource $source, User $admin, string $license, ?string $rightsHolder): void
    {
        if ($source->access_type === AccessType::UserOwned) {
            throw ValidationException::withMessages(['source' => __('A reader\'s own file is not verified by an admin.')]);
        }

        $data = Validator::make(
            ['license' => trim($license), 'rights_holder' => $rightsHolder === null ? null : trim($rightsHolder)],
            ['license' => ['required', 'string', 'max:2000'], 'rights_holder' => ['nullable', 'string', 'max:255']],
        )->validate();

        $source->forceFill([
            'license' => $data['license'],
            'rights_holder' => ($data['rights_holder'] ?? '') === '' ? null : $data['rights_holder'],
            'verified_by' => $admin->id,
            'verified_at' => now(),
        ])->save();
    }
}
