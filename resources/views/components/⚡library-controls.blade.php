<?php

use App\Actions\Library\AddToCollection;
use App\Actions\Library\AddToLibrary;
use App\Actions\Library\CreateCollection;
use App\Actions\Library\LogProgress;
use App\Actions\Library\RemoveFromCollection;
use App\Actions\Library\RemoveFromLibrary;
use App\Actions\Library\SetLibraryStatus;
use App\Actions\Library\ToggleFavorite;
use App\Actions\Ratings\RateMedia;
use App\Enums\ReadingStatus;
use App\Library\WriteLimit;
use App\Models\Collection;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\ReadingProgress;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A reader's library controls for one story, shown on its page: add it, say where they are with it, favorite it, take it out, log how far
 * they have read, put it in (or take it out of) their collections, and rate it. Every action works on the signed-in reader's own entry, progress,
 * collections and rating and nothing else, and re-checks that the story is one they may see. A guest gets nothing.
 */
new class extends Component {
    #[Locked]
    public int $mediaId;

    /** The name typed in the new collection dialog. */
    public string $newCollection = '';

    /**
     * The reader's own score for this story (1 to 10), or null: the model of <x-rating-input>. It comes from the client, so it is checked
     * again on every save and only ever written to the signed-in reader's own rating.
     */
    public mixed $rating = null;

    /** The progress form: a percent from 0 to 100, and optionally the chapter, the page and the minutes spent. Numbers stay strings until they are checked. */
    public string $percent = '';

    public string $chapter = '';

    public string $pageNumber = '';

    public string $minutes = '';

    public function mount(Media $media): void
    {
        $this->mediaId = $media->id;
        $this->rating = auth()->user()?->ratings()->where('media_id', $media->id)->value('score');
    }

    /**
     * The reader's entry for this story, or null when it is not in their library. It comes with where they are in it (`progress_percent`,
     * `progress_chapter` and `progress_page`, null when they have logged nothing), joined in so one query answers for both.
     */
    #[Computed]
    public function entry(): ?LibraryEntry
    {
        $user = auth()->user();

        return $user?->library()
            ->leftJoin('reading_progress', fn (JoinClause $join) => $join->on('reading_progress.user_id', '=', 'user_library.user_id')->on('reading_progress.media_id', '=', 'user_library.media_id'))
            ->where('user_library.media_id', $this->mediaId)
            ->select('user_library.*', 'reading_progress.percent as progress_percent', 'reading_progress.chapter as progress_chapter', 'reading_progress.page as progress_page')
            ->first();
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
     * Save where the reader is: the percent (from 0 to 100) and optionally the chapter, the page and the minutes they spent. A story that is not
     * in the library is added, and one that was wanted or paused becomes Reading (see LogProgress). Nothing else of the reader's is touched.
     */
    public function logProgress(): void
    {
        $user = $this->reader();
        $media = $this->story($user);

        $data = $this->validate([
            'percent' => ['required', 'numeric', 'between:0,100'],
            'chapter' => ['nullable', 'string', 'max:100'],
            'pageNumber' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
        ], attributes: [
            'percent' => __('percent'),
            'chapter' => __('chapter'),
            'pageNumber' => __('page'),
            'minutes' => __('minutes'),
        ]);

        $progress = app(LogProgress::class)->handle(
            $user,
            $media,
            (float) $data['percent'],
            page: filled($data['pageNumber']) ? (int) $data['pageNumber'] : null,
            chapter: filled($data['chapter']) ? trim($data['chapter']) : null,
            seconds: (int) ($data['minutes'] ?: 0) * 60,
        );

        $this->reset('percent', 'chapter', 'pageNumber', 'minutes');
        unset($this->entry);
        $this->dispatch('close-progress');

        Flux::toast(variant: 'success', text: __('Progress saved: :percent% of :title.', ['percent' => ReadingProgress::label($progress->percent), 'title' => $media->title]));
    }

    /**
     * Save the score in `rating` as the reader's rating of this story. Rating does not add the story to the library.
     */
    public function saveRating(): void
    {
        $user = $this->reader();
        $media = $this->story($user);

        $this->validate(['rating' => ['required', 'integer:strict', 'between:1,10']], attributes: ['rating' => __('rating')]);

        app(RateMedia::class)->handle($user, $media, $this->rating);
    }

    /**
     * Take the reader's rating of this story back.
     */
    public function clearRating(): void
    {
        $user = $this->reader();

        app(RateMedia::class)->forget($user, $this->story($user));
        $this->rating = null;
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

        try {
            $collection = app(CreateCollection::class)->handle($user, trim($this->newCollection));
        } catch (ValidationException $e) {
            // The reader has as many collections as they may keep: say so under the field they typed in.
            throw ValidationException::withMessages(['newCollection' => $e->validator->errors()->first()]);
        }

        app(AddToCollection::class)->handle($collection, $media);

        $this->reset('newCollection');
        unset($this->collections);
        $this->dispatch('close-new-collection');

        Flux::toast(variant: 'success', text: __(':title was added to :collection.', ['title' => $media->title, 'collection' => $collection->name]));
    }

    /**
     * The signed-in, verified reader these controls act for. Every action starts here, so this is where a change is counted against the reader's
     * minute (see WriteLimit): one past the limit stops the action before it writes.
     */
    private function reader(): User
    {
        $user = auth()->user();
        abort_unless($user !== null && $user->hasVerifiedEmail(), 403);

        app(WriteLimit::class)->ensure($user);

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
            :aria-label="__('Favorite')"
        />

        @php
            $collections = $this->collections;
            $logged = $entry?->progress_percent !== null ? ReadingProgress::label($entry->progress_percent) : null;
            $progressName = 'progress-'.$mediaId;
        @endphp

        <flux:modal.trigger :name="$progressName">
            <x-cta variant="secondary" icon="bookmark-square" data-test="log-progress">
                @if ($logged !== null)
                    {{ __(':percent% read', ['percent' => $logged]) }}<span class="sr-only">. {{ __('Update your progress') }}</span>
                @else
                    {{ __('Log progress') }}
                @endif
            </x-cta>
        </flux:modal.trigger>

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

        {{-- On a row of its own below the buttons. The model is written as the reader chooses; `rated` says when to save (see <x-rating-input>). --}}
        <x-rating-input
            wire:model="rating"
            x-on:rated="$event.detail.value === null ? $wire.clearRating() : $wire.saveRating()"
            data-test="rating"
            class="order-last basis-full"
        />

        @error('rating')
            <div role="alert" class="order-last basis-full"><flux:error name="rating" /></div>
        @enderror

        <flux:modal :name="$progressName" class="w-full max-w-md text-ink shadow-overlay!" x-on:close-progress.window="$flux.modal(@js($progressName)).close()">
            <form wire:submit="logProgress" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Log your progress') }}</flux:heading>
                    <flux:text class="mt-2">
                        @if ($logged !== null)
                            {{ __('You are at :percent%. Say how far you have got now: the percent is all it needs, the rest is optional.', ['percent' => $logged]) }}
                        @else
                            {{ __('Say how far you have got in this story. The percent is all it needs, the rest is optional.') }}
                        @endif
                    </flux:text>
                </div>

                <flux:input
                    wire:model="percent"
                    type="number"
                    inputmode="decimal"
                    min="0"
                    max="100"
                    step="any"
                    :label="__('Percent read, 0 to 100')"
                    :placeholder="$logged"
                    required
                    autofocus
                />

                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:input wire:model="chapter" :label="__('Chapter (optional)')" :placeholder="$entry?->progress_chapter" maxlength="100" autocomplete="off" />
                    <flux:input wire:model="pageNumber" type="number" inputmode="numeric" min="1" step="1" :label="__('Page (optional)')" :placeholder="$entry?->progress_page" />
                </div>

                <flux:input wire:model="minutes" type="number" inputmode="numeric" min="0" max="1440" step="1" :label="__('Minutes spent (optional)')" :description="__('How long this stretch of reading took.')" />

                <div class="flex flex-wrap justify-end gap-3">
                    <flux:modal.close>
                        <x-cta variant="secondary" icon="x-mark">{{ __('Cancel') }}</x-cta>
                    </flux:modal.close>
                    <x-cta type="submit" icon="check" wire:loading.attr="disabled" wire:target="logProgress">{{ __('Save progress') }}</x-cta>
                </div>
            </form>
        </flux:modal>

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
