<?php

use App\Actions\Library\AddToLibrary;
use App\Models\Media;
use Flux\Flux;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The signed-in layout's ear for the `library-add` browser event that <x-hero> and <x-story-summary> raise from their Add to Library buttons.
 * It adds the story for the reader who is signed in, under the same rules as POST /library (the throttle, and no story behind the mature gate),
 * and answers with a toast. It draws nothing.
 */
new class extends Component {
    #[On('library-add')]
    public function add(mixed $id = null): void
    {
        $user = auth()->user();

        if ($user === null || ! $user->hasVerifiedEmail()) {
            Flux::toast(variant: 'danger', text: __('Sign in to add stories to your library.'));

            return;
        }

        if (RateLimiter::tooManyAttempts("library-add:{$user->id}", 60)) {
            Flux::toast(variant: 'danger', text: __('That is a lot of stories at once. Give it a moment, then try again.'));

            return;
        }

        RateLimiter::hit("library-add:{$user->id}");

        $media = is_int($id) || (is_string($id) && ctype_digit($id))
            ? Media::query()->unmerged()->visibleTo($user)->find((int) $id)
            : null;

        if ($media === null) {
            Flux::toast(variant: 'danger', text: __('That story is not available.'));

            return;
        }

        $entry = app(AddToLibrary::class)->handle($user, $media);

        Flux::toast(
            variant: 'success',
            text: $entry->wasRecentlyCreated
                ? __(':title was added to your library.', ['title' => $media->title])
                : __(':title is already in your library.', ['title' => $media->title]),
        );
    }
}; ?>

<div hidden></div>
