<?php

use App\Actions\Library\AddToLibrary;
use App\Actions\Library\RemoveFromLibrary;
use App\Actions\Library\SetLibraryStatus;
use App\Actions\Library\ToggleFavorite;
use App\Enums\ReadingStatus;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\User;
use Flux\Flux;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A reader's library controls for one story, shown on its page: add it, say where they are with it, favorite it, take it out.
 * Every action works on the signed-in reader's own entry and nothing else, and re-checks that the story is one they may see.
 * A guest gets nothing.
 */
new class extends Component {
    #[Locked]
    public int $mediaId;

    public function mount(Media $media): void
    {
        $this->mediaId = $media->id;
    }

    /**
     * The reader's entry for this story, or null when it is not in their library.
     */
    #[Computed]
    public function entry(): ?LibraryEntry
    {
        $user = auth()->user();

        return $user?->library()->where('media_id', $this->mediaId)->first();
    }

    public function add(): void
    {
        $user = $this->reader();
        $media = $this->story($user);

        app(AddToLibrary::class)->handle($user, $media);
        unset($this->entry);

        Flux::toast(variant: 'success', text: __(':title was added to your library.', ['title' => $media->title]));
    }

    public function setStatus(string $status): void
    {
        $user = $this->reader();
        $media = $this->story($user);

        $status = ReadingStatus::tryFrom($status)
            ?? throw ValidationException::withMessages(['status' => __('Choose one of the listed statuses.')]);

        app(SetLibraryStatus::class)->handle($user, $media, $status);
        unset($this->entry);
    }

    public function toggleFavorite(): void
    {
        $user = $this->reader();

        $entry = app(ToggleFavorite::class)->handle($user, $this->story($user));
        unset($this->entry);

        Flux::toast(text: $entry->favorited_at === null ? __('Removed from your favorites.') : __('Added to your favorites.'));
    }

    public function remove(): void
    {
        $user = $this->reader();
        $media = $this->story($user);

        app(RemoveFromLibrary::class)->handle($user, $media);
        unset($this->entry);

        Flux::toast(text: __(':title was removed from your library.', ['title' => $media->title]));
    }

    /**
     * The signed-in, verified reader these controls act for.
     */
    private function reader(): User
    {
        $user = auth()->user();
        abort_unless($user !== null && $user->hasVerifiedEmail(), 403);

        return $user;
    }

    /**
     * The story, if this reader may see it: one behind the mature gate, or merged away or deleted, is as good as missing.
     */
    private function story(User $user): Media
    {
        return Media::query()->unmerged()->visibleTo($user)->find($this->mediaId) ?? abort(404);
    }
}; ?>

<div class="contents" data-library-controls>
    @auth
        {{-- Read once: a computed that is null is looked up again on every read. --}}
        @php $entry = $this->entry; @endphp

        @if ($entry === null)
            <x-cta icon="plus" wire:click="add" wire:loading.attr="disabled" wire:target="add">{{ __('Add to Library') }}</x-cta>
        @else
            @php $current = $entry->status; @endphp

            <flux:dropdown position="bottom" align="start">
                <x-cta variant="secondary" :icon="$current->icon()" icon:trailing="chevron-down" data-test="library-status">{{ $current->label() }}</x-cta>

                <flux:menu class="shadow-overlay!">
                    @foreach (ReadingStatus::cases() as $status)
                        <flux:menu.item
                            wire:key="status-{{ $status->value }}"
                            wire:click="setStatus('{{ $status->value }}')"
                            :icon="$status->icon()"
                            :icon:trailing="$status === $current ? 'check' : null"
                            :aria-current="$status === $current ? 'true' : null"
                            class="min-h-11! pe-4!"
                        >{{ $status->label() }}</flux:menu.item>
                    @endforeach

                    <flux:menu.separator />

                    <flux:menu.item wire:click="remove" icon="trash" variant="danger" class="min-h-11! pe-4!">{{ __('Remove from library') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        @endif

        @php $favorite = $entry?->favorited_at !== null; @endphp

        <x-cta
            variant="secondary"
            icon="heart"
            :icon:variant="$favorite ? 'solid' : 'outline'"
            wire:click="toggleFavorite"
            :aria-pressed="$favorite ? 'true' : 'false'"
            :aria-label="$favorite ? __('Remove from favorites') : __('Add to favorites')"
        />
    @endauth
</div>
