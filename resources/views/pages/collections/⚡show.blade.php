<?php

use App\Actions\Library\AddToCollection;
use App\Actions\Library\MoveCollectionItem;
use App\Actions\Library\RemoveFromCollection;
use App\Discovery\Search\HybridSearch;
use App\Discovery\Search\SearchFilters;
use App\Enums\Visibility;
use App\Library\WriteLimit;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Media;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One collection: its stories in order, and for its owner the ways to change it (add, remove, reorder by drag or by the move buttons, edit the
 * details, delete). Anyone else who may view it gets the stories as a grid. Every action re-checks who the reader is, and every list of stories
 * goes through the mature gate.
 */
new class extends Component {
    #[Locked]
    public int $collectionId;

    /** Set by a link to a staff pick (`?editors=1`), so the reader's own collection of the same name does not take its place. Only read when the page opens. */
    #[Url]
    public bool $editors = false;

    /**
     * Whose collection the link means (`?owner=7`; 0 for none), so two readers' public collections of the same name are told apart. Read when the page
     * opens, which then sets it to the collection's owner, so the address the reader copies the link from names them. A staff pick has no owner in its
     * address (`except`): it is found by `editors`.
     */
    #[Url(except: 0)]
    public int $owner = 0;

    public string $name = '';

    public string $description = '';

    public string $visibility = 'private';

    public bool $isEditorial = false;

    /** What the owner typed in the add stories box. */
    public string $find = '';

    /** What a screen reader hears after a story is moved (the page is a list that re-renders, so the move is said aloud). */
    public string $moved = '';

    public function mount(string $slug): void
    {
        $collection = Collection::resolveFor($slug, auth()->user(), $this->editors, $this->owner ?: null) ?? abort(404);
        abort_unless(Gate::allows('view', $collection), 404);

        $this->collectionId = $collection->id;
        $this->owner = $collection->is_editorial ? 0 : $collection->user_id;
        $this->name = $collection->name;
        $this->description = (string) $collection->description;
        $this->visibility = $collection->visibility->value;
        $this->isEditorial = $collection->is_editorial;
    }

    /**
     * The header is a band across the whole page, so the layout leaves `<main>` bare (see layouts/app).
     */
    public function render(): View
    {
        return $this->view()->title($this->collection->name)->layoutData(['flush' => true]);
    }

    /**
     * The collection, if this reader may still view it: looked up again on every request, so a collection made private or deleted meanwhile is a 404.
     */
    #[Computed]
    public function collection(): Collection
    {
        $collection = Collection::query()->findOrFail($this->collectionId);
        abort_unless(Gate::allows('view', $collection), 404);

        return $collection;
    }

    #[Computed]
    public function isOwner(): bool
    {
        return Gate::allows('update', $this->collection);
    }

    /**
     * The stories the reader may see, in order, with what a row or a tile reads. A story behind the mature gate stays in the collection but is not listed.
     *
     * @return EloquentCollection<int, CollectionItem>
     */
    #[Computed]
    public function items(): EloquentCollection
    {
        return $this->collection->items()
            ->visibleTo(auth()->user())
            ->with(['media' => fn ($media) => $media->with(['mediaType', 'creators'])])
            ->orderBy('position')->orderBy('id')
            ->get();
    }

    /**
     * Stories the owner may add: found by what they typed, or their library's latest when nothing is typed. Never one already listed, and
     * never one behind the mature gate.
     *
     * @return EloquentCollection<int, Media>
     */
    #[Computed]
    public function suggestions(): EloquentCollection
    {
        $user = auth()->user();

        if (! $this->isOwner) {
            return new EloquentCollection;
        }

        $inside = $this->items->pluck('media_id')->all();
        $find = trim($this->find);

        if (mb_strlen($find) >= 2) {
            $found = app(HybridSearch::class)->search($find, new SearchFilters, $user, 10);
            $found->throttled && $this->addError('find', __('That is a lot of searches at once. Give it a moment, then try again.'));

            $media = (new EloquentCollection($found->items()))->reject(fn (Media $story): bool => in_array($story->id, $inside, true))->values();
        } else {
            $media = Media::query()->unmerged()->visibleTo($user)
                ->join('user_library', fn ($join) => $join->on('user_library.media_id', '=', 'media.id')->where('user_library.user_id', $user->id))
                ->whereNotIn('media.id', $inside)
                ->orderByDesc('user_library.created_at')->orderByDesc('user_library.id')
                ->select('media.*')
                ->limit(8)
                ->get();
        }

        return $media->load(['mediaType', 'creators']);
    }

    public function addItem(int $mediaId): void
    {
        $collection = $this->owned('addItem');
        $media = Media::query()->unmerged()->visibleTo(auth()->user())->find($mediaId) ?? abort(404);

        app(AddToCollection::class)->handle($collection, $media);
        unset($this->items, $this->suggestions);
    }

    /**
     * An item that is not in this collection (or is already gone) is left alone, so a double press or a guessed id does nothing.
     */
    public function removeItem(int $itemId): void
    {
        $collection = $this->owned();
        $item = $collection->items()->find($itemId);

        $item === null || app(RemoveFromCollection::class)->handle($collection, $item);
        unset($this->items, $this->suggestions);
    }

    /**
     * Called by the drag (`wire:sort`, which passes the item and where it was dropped) and by the move buttons with the same two values and the
     * direction of the button (`$direction`), so the keyboard focus goes back to the moved story's own button: the page re-renders the list, and a
     * focus that fell to the top of the page would make every move a long way back for someone using the keyboard.
     */
    public function reorder(int|string $item, int $position, ?string $direction = null): void
    {
        $collection = $this->owned();
        $moved = $collection->items()->with('media:id,title')->find((int) $item);

        if ($moved === null) {
            return;
        }

        app(MoveCollectionItem::class)->handle($collection, $moved, $position, auth()->user());
        unset($this->items);

        $place = ($this->items->search(fn (CollectionItem $listed): bool => $listed->id === $moved->id) ?: 0) + 1;
        $total = $this->items->count();

        $this->moved = __(':title moved to position :position of :total.', ['title' => $moved->media->title, 'position' => $place, 'total' => $total]);

        if (in_array($direction, ['up', 'down'], true)) {
            // The first story has no up button and the last no down button: focus goes to the one it has.
            $direction = match (true) {
                $direction === 'up' && $place === 1 => 'down',
                $direction === 'down' && $place === $total => 'up',
                default => $direction,
            };

            $this->js('document.querySelector(\'[data-move="'.$moved->id.'-'.$direction.'"]\')?.focus()');
        }
    }

    public function save(): void
    {
        $collection = $this->owned();

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'visibility' => ['required', Rule::enum(Visibility::class)],
        ]);

        $collection->update([
            'name' => trim($this->name),
            'description' => trim($this->description) ?: null,
            'visibility' => Visibility::from($this->visibility),
            // Only an admin can make a staff pick (or take it back); anyone else's value is ignored.
            ...(Gate::allows('access-admin') ? ['is_editorial' => $this->isEditorial] : []),
        ]);

        unset($this->collection);
        Flux::toast(variant: 'success', text: __('Collection saved.'));
    }

    public function delete(): void
    {
        $this->owned('delete')->delete();

        $this->redirectRoute('collections.index', navigate: true);
    }

    /**
     * The collection, for an action only its owner may take. Every change starts here, so this is where it is counted against the owner's minute
     * (see WriteLimit): one past the limit stops the action before it writes.
     */
    private function owned(string $ability = 'update'): Collection
    {
        $collection = $this->collection;
        Gate::authorize($ability, $collection);
        app(WriteLimit::class)->ensure(auth()->user());

        return $collection;
    }
}; ?>

@php
    $collection = $this->collection;
    $items = $this->items;
    $isOwner = $this->isOwner;
    $public = $collection->visibility === \App\Enums\Visibility::Public;
    $eyebrow = $collection->is_editorial ? __('Staff pick') : ($isOwner ? __('Your collection') : __('Collection'));
    $guide = filled($collection->description)
        ? $collection->description
        : ($isOwner
            ? __('Add stories below, drag them or use the move buttons to put them in order, and choose who can see the collection.')
            : ($collection->is_editorial ? __('A list picked by the Bookflix editors. Open a story to read more about it.') : __('A list of stories put together by another reader. Open a story to read more about it.')));
    $field = 'focus-ring h-11 w-full rounded-control border border-border-input bg-surface-input px-3 text-body text-ink placeholder:text-ink-muted';
    $iconButton = 'focus-ring inline-flex size-11 shrink-0 items-center justify-center rounded-control text-ink-subtle hover:bg-surface-input hover:text-ink disabled:cursor-default disabled:text-ink-muted disabled:hover:bg-transparent';
@endphp

<div class="pb-12">
    <header class="bg-surface-deep px-gutter py-8 lg:py-12">
        <x-page-header :title="$collection->name" :description="$guide" :eyebrow="$eyebrow">
            @if ($isOwner)
                <x-slot:actions>
                    <x-cta variant="secondary" icon="plus" href="#add-stories">{{ __('Add stories') }}</x-cta>
                </x-slot:actions>
            @endif
        </x-page-header>

        <x-library-tabs current="collections.index" :exact="false" class="mt-6" />
    </header>

    <div class="px-gutter">
        <p role="status" data-test="collection-count" class="mt-8 flex flex-wrap items-center gap-x-2 text-meta text-ink-subtle">
            <span class="tabular-nums">{{ trans_choice('{0} No stories yet|{1} 1 story|[2,*] :count stories', $items->count()) }}</span>
            @unless ($collection->is_editorial)
                <span aria-hidden="true">·</span>
                <span class="inline-flex items-center gap-1"><flux:icon :name="$public ? 'globe-alt' : 'lock-closed'" variant="micro" class="size-4" />{{ $public ? __('Public') : __('Private') }}</span>
            @endunless
        </p>

        <p class="sr-only" role="status" aria-live="polite" data-test="moved">{{ $moved }}</p>

        <section aria-labelledby="stories-title" class="mt-4">
            <h2 id="stories-title" class="sr-only">{{ __('Stories') }}</h2>

            @if ($items->isEmpty())
                <x-empty-state :title="__('Nothing here yet')" heading="h3" class="mt-8">
                    <p class="mt-2 text-body text-ink-subtle">
                        {{ $isOwner ? __('Search for a story below, or pick one from your library. You can also use the collection button on any story page.') : __('There are no stories in this collection that you can see yet.') }}
                    </p>

                    <div class="mt-6 flex flex-wrap gap-3">
                        @if ($isOwner)
                            <x-cta variant="secondary" icon="plus" href="#add-stories">{{ __('Add stories') }}</x-cta>
                        @else
                            <x-cta variant="secondary" icon="compass" :href="route('discover')" wire:navigate>{{ __('Browse Discover') }}</x-cta>
                        @endif
                    </div>
                </x-empty-state>
            @elseif ($isOwner)
                <ul wire:sort="reorder" wire:sort:config="{ animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 150 }" data-view="rows" class="divide-y divide-hairline border-y border-hairline">
                    @foreach ($items as $item)
                        @php $story = $item->media; @endphp
                        <li wire:sort:item="{{ $item->id }}" wire:key="item-{{ $item->id }}" class="grid grid-cols-[auto_1fr] items-center gap-x-4 gap-y-1 bg-canvas py-3 sm:grid-cols-[auto_auto_1fr_auto] sm:gap-x-4">
                            <span wire:sort:handle aria-hidden="true" title="{{ __('Drag to reorder') }}" class="max-sm:hidden inline-flex size-11 cursor-grab touch-none items-center justify-center rounded-control text-ink-muted hover:bg-surface-input hover:text-ink active:cursor-grabbing">
                                <flux:icon name="bars-3" class="size-5" />
                            </span>

                            <a href="{{ route('stories.show', $story->slug) }}" wire:navigate tabindex="-1" aria-hidden="true" class="block aspect-[2/3] w-12 shrink-0 overflow-hidden rounded-control bg-surface-card sm:w-14">
                                @if ($story->cover_url)
                                    <img src="{{ $story->cover_url }}" alt="" width="56" height="84" loading="lazy" decoding="async" draggable="false" class="size-full object-cover">
                                @else
                                    <x-cover-fallback :title="$story->title" compact />
                                @endif
                            </a>

                            <div class="min-w-0">
                                <p class="text-lead text-ink"><a href="{{ route('stories.show', $story->slug) }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center rounded-control hover:underline">{{ $story->title }}</a></p>
                                <p class="text-meta text-ink-subtle">
                                    <span class="tabular-nums">{{ $loop->iteration }}</span>
                                    <span aria-hidden="true">·</span>
                                    {{ $story->mediaType->name }}@if ($story->creators->isNotEmpty())<span aria-hidden="true"> · </span><span class="sr-only">, </span>{{ $story->creators->sortBy('pivot.position')->pluck('name')->unique()->take(2)->implode(', ') }}@endif
                                </p>
                            </div>

                            <div class="col-span-2 flex items-center justify-end gap-1 sm:col-span-1">
                                {{-- The first has no up and the last no down; a blank of the same size keeps the buttons in their columns. --}}
                                @if ($loop->first)
                                    <span aria-hidden="true" class="size-11 shrink-0"></span>
                                @else
                                    <button type="button" wire:click="reorder({{ $item->id }}, {{ $loop->index - 1 }}, 'up')" data-move="{{ $item->id }}-up" wire:key="up-{{ $item->id }}" aria-label="{{ __('Move :title up', ['title' => $story->title]) }}" class="{{ $iconButton }}">
                                        <flux:icon name="arrow-up" class="size-5" />
                                    </button>
                                @endif
                                @if ($loop->last)
                                    <span aria-hidden="true" class="size-11 shrink-0"></span>
                                @else
                                    <button type="button" wire:click="reorder({{ $item->id }}, {{ $loop->index + 1 }}, 'down')" data-move="{{ $item->id }}-down" wire:key="down-{{ $item->id }}" aria-label="{{ __('Move :title down', ['title' => $story->title]) }}" class="{{ $iconButton }}">
                                        <flux:icon name="arrow-down" class="size-5" />
                                    </button>
                                @endif
                                <button type="button" wire:click="removeItem({{ $item->id }})" wire:key="remove-{{ $item->id }}" aria-label="{{ __('Remove :title from this collection', ['title' => $story->title]) }}" class="{{ $iconButton }}">
                                    <flux:icon name="x-mark" class="size-5" />
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @else
                <ul data-view="grid" class="mt-4 grid grid-cols-2 gap-x-4 gap-y-6 sm:grid-cols-[repeat(auto-fill,minmax(11rem,1fr))]">
                    @foreach ($items as $item)
                        <li wire:key="item-{{ $item->id }}">
                            <x-cover-tile :media="$item->media" fluid />
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($isOwner)
            <section id="add-stories" aria-labelledby="add-title" class="mt-12 scroll-mt-20 lg:mt-16">
                <h2 id="add-title" class="text-section text-ink">{{ __('Add Stories') }}</h2>
                <p class="mt-1 text-meta text-ink-subtle">{{ __('Search the catalog by title, creator or subject. With nothing typed, these are the latest stories in your library.') }}</p>

                <div class="mt-4 max-w-xl">
                    <label for="collection-find" class="sr-only">{{ __('Search the catalog') }}</label>
                    <input
                        id="collection-find"
                        type="search"
                        wire:model.live.debounce.300ms="find"
                        maxlength="200"
                        autocomplete="off"
                        placeholder="{{ __('Title, creator or subject') }}"
                        class="{{ $field }}"
                    >
                    <div role="alert"><flux:error name="find" class="mt-2" /></div>
                    <div role="alert"><flux:error name="collection" class="mt-2" /></div>
                </div>

                @php $suggestions = $this->suggestions; @endphp
                <div wire:loading.delay.shortest.class="opacity-60" wire:target="find" class="motion-safe:transition-opacity">
                    @if ($suggestions->isNotEmpty())
                        <h3 class="mt-6 text-meta text-ink-subtle">{{ filled(trim($find)) ? __('Matches') : __('From your library') }}</h3>
                        <ul data-view="suggestions" class="mt-2 max-w-3xl divide-y divide-hairline border-y border-hairline">
                            @foreach ($suggestions as $story)
                                <li wire:key="suggestion-{{ $story->id }}" class="flex items-center gap-4 py-3">
                                    <span aria-hidden="true" class="block aspect-[2/3] w-10 shrink-0 overflow-hidden rounded-control bg-surface-card">
                                        @if ($story->cover_url)
                                            <img src="{{ $story->cover_url }}" alt="" width="40" height="60" loading="lazy" decoding="async" class="size-full object-cover">
                                        @else
                                            <x-cover-fallback :title="$story->title" compact />
                                        @endif
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="line-clamp-1 text-body text-ink">{{ $story->title }}</p>
                                        <p class="line-clamp-1 text-meta text-ink-subtle">{{ $story->mediaType->name }}@if ($story->creators->isNotEmpty()) · {{ $story->creators->sortBy('pivot.position')->pluck('name')->unique()->take(2)->implode(', ') }}@endif</p>
                                    </div>
                                    <x-cta variant="secondary" icon="plus" wire:click="addItem({{ $story->id }})" wire:loading.attr="disabled" wire:target="addItem({{ $story->id }})">{{ __('Add') }}<span class="sr-only"> {{ $story->title }}</span></x-cta>
                                </li>
                            @endforeach
                        </ul>
                    @elseif (mb_strlen(trim($find)) >= 2)
                        <p class="mt-6 max-w-prose text-body text-ink-subtle">{{ __('No story matches that. Try a title, a creator or a subject.') }}</p>
                    @else
                        <p class="mt-6 max-w-prose text-body text-ink-subtle">{{ __('Nothing from your library is left to add. Search the catalog above.') }}</p>
                    @endif
                </div>
            </section>

            <section id="details" aria-label="{{ __('Collection settings') }}" class="mt-12 scroll-mt-20 lg:mt-16">
                <x-settings-card :title="__('Details')" :description="__('The name, a line about the collection, and who can see it.')" class="max-w-2xl">
                    <form wire:submit="save" class="w-full space-y-6">
                        <flux:input wire:model="name" :label="__('Name')" maxlength="100" autocomplete="off" />

                        <flux:textarea wire:model="description" :label="__('Description')" :placeholder="__('What this collection is for')" rows="3" maxlength="500" />

                        <flux:radio.group wire:model="visibility" :label="__('Who can see it')">
                            <flux:radio value="private" :label="__('Private')" :description="__('Only you can see this collection.')" />
                            <flux:radio value="public" :label="__('Public')" :description="__('Anyone with the link can open it.')" />
                        </flux:radio.group>

                        @can('access-admin')
                            <flux:switch wire:model="isEditorial" :label="__('Show as an editor pick')" :description="__('Staff picks are open to every reader and appear as a themed row on Discover and Home.')" />
                        @endcan

                        <x-cta variant="secondary" type="submit" icon="check" icon:variant="outline" data-test="save-collection">{{ __('Save') }}</x-cta>
                    </form>
                </x-settings-card>

                <x-settings-card :title="__('Delete This Collection')" :description="__('The collection goes away. The stories stay in the catalog and in your library.')" danger class="mt-6 max-w-2xl">
                    <flux:modal.trigger name="delete-collection">
                        <x-cta variant="danger" icon="trash" data-test="delete-collection">{{ __('Delete collection') }}</x-cta>
                    </flux:modal.trigger>
                </x-settings-card>

                <flux:modal name="delete-collection" class="max-w-md space-y-6 text-ink shadow-overlay!">
                    <div>
                        <flux:heading size="lg">{{ __('Delete this collection?') }}</flux:heading>
                        <flux:text class="mt-2">{{ __(':name will be deleted for good. Its stories are not.', ['name' => $collection->name]) }}</flux:text>
                    </div>

                    <div class="flex flex-wrap justify-end gap-3">
                        <flux:modal.close>
                            <x-cta variant="secondary" icon="x-mark">{{ __('Keep it') }}</x-cta>
                        </flux:modal.close>
                        <x-cta variant="danger" icon="trash" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">{{ __('Delete') }}</x-cta>
                    </div>
                </flux:modal>
            </section>
        @endif
    </div>
</div>
