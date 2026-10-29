<?php

use App\Actions\Library\AddToCollection;
use App\Actions\Library\AddToLibrary;
use App\Actions\Library\CreateCollection;
use App\Actions\Library\RemoveFromCollection;
use App\Actions\Library\RemoveFromLibrary;
use App\Actions\Library\SetLibraryStatus;
use App\Actions\Library\ToggleFavorite;
use App\Enums\ReadingStatus;
use App\Models\Collection;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A reader's library controls for one story, shown on its page: add it, say where they are with it, favorite it, take it out, and put it in
 * (or take it out of) their collections. Every action works on the signed-in reader's own entry and collections and nothing else, and
 * re-checks that the story is one they may see. A guest gets nothing.
 */
new class extends Component {
    #[Locked]
    public int $mediaId;

    /** The name typed in the new collection dialog. */
    public string $newCollection = '';

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

    /**
     * The reader's collections, the most recently changed first (the most they keep in the menu is 50), each with `holds_story` for this story.
     *
     * @return EloquentCollection<int, Collection>
     */
    #[Computed]
    public function collections(): EloquentCollection
    {
        $user = auth()->user();

        if ($user === null) {
            return new EloquentCollection;
        }

        return $user->collections()
            ->withExists(['items as holds_story' => fn ($items) => $items->where('media_id', $this->mediaId)])
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(50)
            ->get();
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
     * Put the story in one of the reader's collections, or take it out when it is there.
     */
    public function toggleCollection(int $collectionId): void
    {
        $user = $this->reader();
        $media = $this->story($user);
        $collection = $user->collections()->find($collectionId) ?? abort(404);

        $item = $collection->items()->where('media_id', $media->id)->first();

        try {
            $item === null
                ? app(AddToCollection::class)->handle($collection, $media)
                : app(RemoveFromCollection::class)->handle($collection, $item);
        } catch (ValidationException $e) {
            Flux::toast(variant: 'danger', text: $e->validator->errors()->first());

            return;
        }

        unset($this->collections);

        Flux::toast(text: $item === null
            ? __(':title was added to :collection.', ['title' => $media->title, 'collection' => $collection->name])
            : __(':title was removed from :collection.', ['title' => $media->title, 'collection' => $collection->name]));
    }

    /**
     * Start a collection with the name typed in the dialog, and put the story in it.
     */
    public function createCollection(): void
    {
        $user = $this->reader();
        $media = $this->story($user);

        $this->validate(['newCollection' => ['required', 'string', 'max:100']], attributes: ['newCollection' => __('name')]);

        $collection = app(CreateCollection::class)->handle($user, trim($this->newCollection));
        app(AddToCollection::class)->handle($collection, $media);

        $this->reset('newCollection');
        unset($this->collections);
        $this->dispatch('close-new-collection');

        Flux::toast(variant: 'success', text: __(':title was added to :collection.', ['title' => $media->title, 'collection' => $collection->name]));
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

        @php $collections = $this->collections; @endphp

        <flux:dropdown position="bottom" align="start">
            <x-cta variant="secondary" icon="folder-plus" icon:trailing="chevron-down" data-test="collection-menu">{{ __('Add to collection') }}</x-cta>

            <flux:menu class="shadow-overlay!">
                @if ($collections->isNotEmpty())
                    <flux:menu.heading>{{ __('Your collections') }}</flux:menu.heading>

                    @foreach ($collections as $collection)
                        <flux:menu.checkbox
                            wire:key="collection-{{ $collection->id }}"
                            wire:click="toggleCollection({{ $collection->id }})"
                            :checked="$collection->holds_story"
                            class="min-h-11! pe-4!"
                        >{{ $collection->name }}</flux:menu.checkbox>
                    @endforeach

                    <flux:menu.separator />
                @endif

                <flux:modal.trigger :name="'new-collection-'.$mediaId">
                    <flux:menu.item icon="plus" class="min-h-11! pe-4!">{{ __('New collection') }}</flux:menu.item>
                </flux:modal.trigger>
            </flux:menu>
        </flux:dropdown>

        <flux:modal :name="'new-collection-'.$mediaId" class="w-full max-w-md text-ink shadow-overlay!" x-on:close-new-collection.window="$flux.modal(@js('new-collection-'.$mediaId)).close()">
            <form wire:submit="createCollection" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('New collection') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Name it, and this story goes in. Add a description or more stories later, from the collection.') }}</flux:text>
                </div>

                <flux:input wire:model="newCollection" :label="__('Name')" maxlength="100" autocomplete="off" autofocus />

                <div class="flex flex-wrap justify-end gap-3">
                    <flux:modal.close>
                        <x-cta variant="secondary" icon="x-mark">{{ __('Cancel') }}</x-cta>
                    </flux:modal.close>
                    <x-cta type="submit" icon="plus" wire:loading.attr="disabled" wire:target="createCollection">{{ __('Create collection') }}</x-cta>
                </div>
            </form>
        </flux:modal>
    @endauth
</div>
