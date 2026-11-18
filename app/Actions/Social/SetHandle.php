<?php

namespace App\Actions\Social;

use App\Enums\Visibility;
use App\Models\User;
use App\Rules\Handle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Give a reader their public handle, or take it away. The input is trimmed and lowercased, so uniqueness is case-insensitive; the old handle is
 * free for anyone the moment it changes, and its old URL answers 404 (P10-R2). A reader whose profile is public can't clear it: a public
 * profile needs a handle. Failures are a ValidationException on `handle`.
 */
class SetHandle
{
    public function handle(User $user, ?string $input): ?string
    {
        $handle = $input === null ? '' : mb_strtolower(trim($input));
        $profile = $user->profile;

        if ($handle === '') {
            if ($profile->profile_visibility === Visibility::Public) {
                throw ValidationException::withMessages(['handle' => __('A public profile needs a handle. Make it private first.')]);
            }

            $this->store($user, null);

            return null;
        }

        Validator::make(['handle' => $handle], ['handle' => [new Handle($user->id)]])->validate();

        try {
            $this->store($user, $handle);
        } catch (UniqueConstraintViolationException) {
            // Someone took it between the check and the write.
            throw ValidationException::withMessages(['handle' => __('That handle is already taken.')]);
        }

        return $handle;
    }

    private function store(User $user, ?string $handle): void
    {
        $user->setRelation('profile', $user->profile()->updateOrCreate([], ['handle' => $handle]));
    }
}
