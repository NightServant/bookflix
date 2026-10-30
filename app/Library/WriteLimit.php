<?php

namespace App\Library;

use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * How many changes one reader may make to their library in a minute through the Livewire pages (see config `bookflix.library`). There is one
 * counter per reader, shared by the story page's controls and the collection pages, so no page is a way round the limit; looking is not counted.
 * Past it, a Livewire action stops before it writes anything: the reader gets a toast, and the `limit` error is set on the component.
 */
class WriteLimit
{
    /**
     * Count one change for this reader. Call it from a Livewire action, once the reader is known and before anything is written.
     *
     * @throws ValidationException (`limit`) when the reader has made as many changes as they may in the last minute
     */
    public function ensure(User $user): void
    {
        $allowed = RateLimiter::attempt("library-write:{$user->id}", (int) config('bookflix.library.writes_per_minute'), fn (): bool => true, 60);

        if ($allowed) {
            return;
        }

        $message = __('That is a lot of changes at once. Give it a moment, then try again.');
        Flux::toast(variant: 'danger', text: $message);

        throw ValidationException::withMessages(['limit' => $message]);
    }
}
