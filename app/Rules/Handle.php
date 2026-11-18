<?php

namespace App\Rules;

use App\Models\Profile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A profile handle (P10-R2): 3 to 32 of a-z, 0-9 and underscore, not reserved, and not taken by another reader. Handles are stored lowercase, so the
 * value is checked as given and callers lowercase it first (see SetHandle).
 */
class Handle implements ValidationRule
{
    /**
     * @param  int|null  $ownerId  The reader who may keep their own handle.
     */
    public function __construct(private readonly ?int $ownerId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[a-z0-9_]{3,32}$/', $value) !== 1) {
            $fail(__('A handle is 3 to 32 characters: lowercase letters, numbers and underscores.'));

            return;
        }

        if (in_array($value, config('bookflix.social.reserved_handles'), true)) {
            $fail(__('That handle is reserved. Choose another.'));

            return;
        }

        $taken = Profile::query()->where('handle', $value)->when($this->ownerId !== null, fn ($query) => $query->where('user_id', '!=', $this->ownerId))->exists();

        if ($taken) {
            $fail(__('That handle is already taken.'));
        }
    }
}
