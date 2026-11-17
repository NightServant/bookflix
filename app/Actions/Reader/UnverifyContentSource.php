<?php

namespace App\Actions\Reader;

use App\Enums\AccessType;
use App\Models\ContentSource;
use Illuminate\Validation\ValidationException;

/**
 * Take a source's verification back. Both stamps are cleared, so the gate refuses it at once, even to a reader in the middle of the book.
 */
class UnverifyContentSource
{
    /**
     * @throws ValidationException
     */
    public function handle(ContentSource $source): void
    {
        if ($source->access_type === AccessType::UserOwned) {
            throw ValidationException::withMessages(['source' => __('A reader\'s own file is not verified by an admin.')]);
        }

        $source->forceFill(['verified_by' => null, 'verified_at' => null])->save();
    }
}
