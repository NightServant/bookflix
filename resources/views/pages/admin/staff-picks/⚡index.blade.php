<?php

use App\Actions\Library\SaveStaffPick;
use App\Models\Collection;
use App\Models\Media;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Staff picks (P10-R3): the collections admins make. Switched on, each shows to every reader as a themed row on Discover and Home (see
 * EditorialCollections); switched off, it stays here as a draft. Readers' collections are private and never listed. The editor (a flyout) sets the
 * name, description, the switch and the stories in order; only stories every reader may see can be added (see SaveStaffPick).
 */
new #[Title('Staff picks')] class extends Component {
    use WithPagination;

    private const RESULTS = 6;

    #[Url]
    public string $show = '';

    public bool $editing = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    public bool $shown = false;

    /** @var list<int> */
    public array $storyIds = [];

    public string $search = '';

    public function boot(): void
    {
        Gate::authorize('access-admin');
    }

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    /**
     * The most recently changed first. Only the columns the table shows are read; the maker is named, never their email.
     *
     * @return LengthAwarePaginator<int, Collection>
     */
    #[Computed]
    public function collections(): LengthAwarePaginator
    {
        return $this->picks()
            ->when($this->show === 'shown', fn (Builder $query) => $query->where('is_editorial', true))
            ->when($this->show === 'off', fn (Builder $query) => $query->where('is_editorial', false))
            ->select(['id', 'user_id', 'name', 'description', 'is_editorial', 'updated_at'])
            ->with('user:id,name')
            ->withCount('items')
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(25);
    }

    /**
     * @return array{all: int, shown: int}
     */
    #[Computed]
    public function counts(): array
    {
        $row = $this->picks()->toBase()->selectRaw('count(*) as total, count(*) filter (where is_editorial) as shown')->first();

        return ['all' => (int) $row->total, 'shown' => (int) $row->shown];
    }

    /**
     * The editor's stories, in order, as every reader would see them.
     *
     * @return list<Media>
     */
    #[Computed]
    public function stories(): array
    {
        $found = Media::query()->unmerged()->visibleTo(null)->whereIn('id', $this->storyIds)->get()->keyBy('id');

        return array_values(array_filter(array_map(fn (int $id): ?Media => $found->get($id), $this->storyIds)));
    }

    /**
     * Stories to add: by title or by a creator's name, only those every reader may see, never one already on the list.
     *
     * @return EloquentCollection<int, Media>
     */
    #[Computed]
    public function results(): EloquentCollection
    {
        $term = trim($this->search);

        if (mb_strlen($term) < 2) {
            return new EloquentCollection;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return Media::query()->unmerged()->visibleTo(null)
            ->where(fn (Builder $query) => $query->where('title', 'ilike', $like)->orWhereHas('creators', fn (Builder $creator) => $creator->where('name', 'ilike', $like)))
            ->whereNotIn('id', $this->storyIds)
            ->with('creators:id,name')
            ->orderBy('title')->orderBy('id')
            ->limit(self::RESULTS)
            ->get();
    }

    public function toggle(int $collectionId): void
    {
        Gate::authorize('access-admin');

        $pick = $this->picks()->findOrFail($collectionId);
        $shown = ! $pick->is_editorial;

        // The "Updated" date stays the list's: showing a pick is not an edit of it.
        $pick->timestamps = false;
        $pick->forceFill(['is_editorial' => $shown])->save();

        unset($this->collections, $this->counts);

        Flux::toast(variant: 'success', text: $shown
            ? __(':name now shows to readers.', ['name' => $pick->name])
            : __(':name no longer shows to readers.', ['name' => $pick->name]));
    }

    public function create(): void
    {
        $this->resetEditor();
        $this->editing = true;
        Flux::modal('staff-pick-editor')->show();
    }

    public function edit(int $collectionId): void
    {
        $pick = $this->picks()->findOrFail($collectionId);

        $this->resetEditor();
        $this->editing = true;
        $this->editingId = $pick->id;
        $this->name = $pick->name;
        $this->description = (string) $pick->description;
        $this->shown = $pick->is_editorial;
        $this->storyIds = $pick->items()
            ->whereHas('media', fn (Builder $media) => $media->unmerged()->visibleTo(null))
            ->orderBy('position')->orderBy('id')
            ->pluck('media_id')->map(fn ($id): int => (int) $id)->all();

        Flux::modal('staff-pick-editor')->show();
    }

    public function addStory(int $mediaId): void
    {
        $media = Media::query()->unmerged()->visibleTo(null)->findOrFail($mediaId);

        $limit = (int) config('bookflix.collections.max_items');

        if (count($this->storyIds) >= $limit) {
            $this->addError('stories', trans_choice('A collection holds at most :count story.|A collection holds at most :count stories.', $limit));

            return;
        }

        if (! in_array($media->id, $this->storyIds, true)) {
            $this->storyIds[] = $media->id;
        }

        $this->search = '';
    }

    public function removeStory(int $mediaId): void
    {
        $this->storyIds = array_values(array_filter($this->storyIds, fn (int $id): bool => $id !== $mediaId));
    }

    /**
     * Move the story at `index` one place up (-1) or down (+1).
     */
    public function moveStory(int $index, int $delta): void
    {
        $target = $index + ($delta < 0 ? -1 : 1);

        if (! isset($this->storyIds[$index], $this->storyIds[$target])) {
            return;
        }

        [$this->storyIds[$index], $this->storyIds[$target]] = [$this->storyIds[$target], $this->storyIds[$index]];
    }

    public function save(): void
    {
        $pick = $this->editingId === null ? null : $this->picks()->findOrFail($this->editingId);
        $created = $pick === null;

        $pick = app(SaveStaffPick::class)->handle(auth()->user(), $pick, $this->name, $this->description, $this->shown, $this->storyIds);

        $this->closeEditor();
        Flux::toast(variant: 'success', text: $created ? __(':name is saved.', ['name' => $pick->name]) : __(':name is updated.', ['name' => $pick->name]));
    }

    public function delete(): void
    {
        Gate::authorize('access-admin');

        $pick = $this->picks()->findOrFail((int) $this->editingId);
        $pick->delete();

        $this->closeEditor();
        Flux::toast(text: __(':name is deleted. Its stories are not.', ['name' => $pick->name]));
    }

    public function cancel(): void
    {
        $this->closeEditor();
    }

    private function closeEditor(): void
    {
        $this->resetEditor();
        Flux::modal('staff-pick-editor')->close();
        unset($this->collections, $this->counts);
    }

    private function resetEditor(): void
    {
        $this->reset('editing', 'editingId', 'name', 'description', 'shown', 'storyIds', 'search');
        $this->resetValidation();
    }

    /**
     * Every staff pick, shown or not: the collections admins own (an admin has no reader side, so no collection of theirs is private).
     *
     * @return Builder<Collection>
     */
    private function picks(): Builder
    {
        // Also any collection still shown to readers, so a pick whose maker is no longer an admin can still be switched off or edited.
        return Collection::query()->where(fn (Builder $query) => $query
            ->where('is_editorial', true)
            ->orWhereHas('user', fn (Builder $user) => $user->where('is_admin', true)));
    }
}; ?>

@php
    $counts = $this->counts;
    $chip = 'focus-ring inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full px-4 text-meta';
@endphp

<section class="py-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <x-admin-header :title="__('Staff picks')" :description="__('Collections the Bookflix editors make. Switched on, each one shows to every reader as a themed row on Discover and Home. Readers’ own collections are private and never listed here.')" />
        <x-cta variant="light" icon="plus" wire:click="create">{{ __('New staff pick') }}</x-cta>
    </div>

    <div role="group" aria-label="{{ __('Show') }}" class="mt-6 flex flex-wrap gap-2">
        @foreach (['' => [__('All'), $counts['all']], 'shown' => [__('Shown to readers'), $counts['shown']], 'off' => [__('Off'), $counts['all'] - $counts['shown']]] as $value => [$label, $total])
            <button type="button" wire:click="$set('show', '{{ $value }}')" aria-pressed="{{ $show === $value ? 'true' : 'false' }}" @class([$chip, 'bg-ink text-canvas' => $show === $value, 'bg-surface-card text-ink hover:bg-hairline' => $show !== $value])>
                @if ($show === $value)<flux:icon name="check" variant="micro" class="size-4" />@endif
                {{ $label }} <span class="tabular-nums">{{ Number::format($total) }}</span>
            </button>
        @endforeach
    </div>

    @if ($this->collections->isEmpty())
        <x-admin-empty class="mt-6 rounded-card bg-surface-card" icon="star" :title="__('No staff picks yet')" :description="__('Make one with New staff pick: name it, add stories, and switch it on when it is ready.')" />
    @else
        {{-- A table from md up; below it each collection is a card, each cell named by its data-label (the header row is hidden there). --}}
        <flux:table class="mt-6 table-auto! max-md:block max-md:whitespace-normal! max-md:[&_tbody]:block max-md:[&_thead]:hidden max-md:[&_tr]:block max-md:[&_td]:block max-md:[&_td]:border-0 max-md:[&_td]:px-0">
            <flux:table.columns>
                <flux:table.column>{{ __('Collection') }}</flux:table.column>
                <flux:table.column>{{ __('Made by') }}</flux:table.column>
                <flux:table.column>{{ __('Stories') }}</flux:table.column>
                <flux:table.column>{{ __('Updated') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Shown to readers') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->collections as $collection)
                    @php $cell = 'max-md:flex! max-md:gap-4 max-md:py-1 max-md:before:w-24 max-md:before:shrink-0 max-md:before:text-ink-subtle max-md:before:content-[attr(data-label)]'; @endphp
                    <flux:table.row wire:key="collection-{{ $collection->id }}" class="max-md:mb-4 max-md:rounded-card max-md:bg-surface-card max-md:p-4">
                        <flux:table.cell class="md:max-w-96 md:whitespace-normal!">
                            <button type="button" wire:click="edit({{ $collection->id }})" class="focus-ring block min-h-11 cursor-pointer rounded-control text-start">
                                <span class="block text-body text-ink hover:underline">{{ $collection->name }}</span>
                                @if ($collection->description)
                                    <span class="block line-clamp-1 text-meta text-ink-subtle">{{ $collection->description }}</span>
                                @endif
                                <span class="sr-only">{{ __('Edit') }}</span>
                            </button>
                        </flux:table.cell>
                        <flux:table.cell data-label="{{ __('Made by') }}" class="{{ $cell }}">{{ $collection->user?->name }}</flux:table.cell>
                        <flux:table.cell data-label="{{ __('Stories') }}" class="{{ $cell }} tabular-nums">{{ Number::format($collection->items_count) }}</flux:table.cell>
                        <flux:table.cell data-label="{{ __('Updated') }}" class="{{ $cell }}">{{ $collection->updated_at?->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell data-label="{{ __('Shown to readers') }}" class="{{ $cell }} max-md:items-center">
                            <div class="flex items-center gap-3 md:justify-end max-md:flex-1 max-md:justify-end">
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked="{{ $collection->is_editorial ? 'true' : 'false' }}"
                                    aria-label="{{ __('Shown to readers: :name', ['name' => $collection->name]) }}"
                                    wire:click="toggle({{ $collection->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="toggle({{ $collection->id }})"
                                    data-test="staff-pick-{{ $collection->id }}"
                                    class="focus-ring inline-flex min-h-11 min-w-11 cursor-pointer items-center justify-center rounded-control disabled:opacity-50"
                                >
                                    <span @class(['flex h-6 w-11 items-center rounded-full p-0.75 transition-colors', 'justify-end bg-accent-electric' => $collection->is_editorial, 'justify-start bg-surface-input' => ! $collection->is_editorial])>
                                        <span @class(['size-4.5 rounded-full', 'bg-ink' => $collection->is_editorial, 'bg-ink-subtle' => ! $collection->is_editorial])></span>
                                    </span>
                                </button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-meta text-ink-subtle">
                {{ trans_choice(':count staff pick|:count staff picks', $counts['all'], ['count' => Number::format($counts['all'])]) }} · {{ __(':count shown to readers', ['count' => Number::format($counts['shown'])]) }}
            </p>
            <x-pager :paginator="$this->collections" class="justify-end" />
        </div>
    @endif

    {{-- The editor: a flyout (a native modal <dialog>: focus moves in, Escape closes, focus goes back to what opened it). --}}
    <flux:modal name="staff-pick-editor" variant="flyout" :closable="false" class="w-full max-w-lg text-ink shadow-overlay!" x-on:close="$wire.editing && $wire.cancel()">
        <form wire:submit="save" class="flex min-h-full flex-col gap-5">
            <div class="flex items-start justify-between gap-4">
                <flux:heading size="xl" level="2" tabindex="-1" autofocus class="outline-none">{{ $editingId ? __('Edit staff pick') : __('New staff pick') }}</flux:heading>
                <flux:modal.close>
                    <x-cta variant="secondary" type="button" icon="x-mark" :aria-label="__('Close')" class="-me-2 -mt-2 bg-transparent! hover:bg-surface-card!" />
                </flux:modal.close>
            </div>

            <flux:input wire:model="name" :label="__('Name')" maxlength="100" required />
            <flux:textarea wire:model="description" :label="__('Description')" rows="3" maxlength="500" />

            <label class="flex min-h-11 cursor-pointer items-center gap-3">
                <button type="button" role="switch" aria-checked="{{ $shown ? 'true' : 'false' }}" wire:click="$toggle('shown')" aria-labelledby="pick-shown-label" aria-describedby="pick-shown-help" class="focus-ring inline-flex min-h-11 min-w-11 cursor-pointer items-center justify-center rounded-control">
                    <span @class(['flex h-6 w-11 items-center rounded-full p-0.75 transition-colors', 'justify-end bg-accent-electric' => $shown, 'justify-start bg-surface-input' => ! $shown])>
                        <span @class(['size-4.5 rounded-full', 'bg-ink' => $shown, 'bg-ink-subtle' => ! $shown])></span>
                    </span>
                </button>
                <span>
                    <span id="pick-shown-label" class="block text-body text-ink">{{ __('Show to readers') }}</span>
                    <span id="pick-shown-help" class="block text-legal text-ink-subtle">{{ __('As a row on Discover and Home') }}</span>
                </span>
            </label>

            <div>
                <h3 class="text-meta font-medium text-ink">{{ __('Stories') }} · <span class="tabular-nums">{{ count($storyIds) }}</span></h3>

                <div class="relative mt-2">
                    {{-- Escape in a filled search clears it (and its results) instead of closing the editor and losing the draft; empty, it closes as usual. --}}
                    <flux:input type="search" icon="magnifying-glass" wire:model.live.debounce.300ms="search" :placeholder="__('Add a story by title or author')" :aria-label="__('Add a story by title or author')" autocomplete="off"
                        x-on:keydown.escape="if ($el.value !== '') { $event.preventDefault(); $event.stopPropagation(); $el.value = ''; $wire.set('search', '') }" />

                    @if ($this->results->isNotEmpty())
                        <ul class="mt-1 rounded-card border border-hairline bg-surface-deep p-1" aria-label="{{ __('Stories found') }}">
                            @foreach ($this->results as $result)
                                <li wire:key="result-{{ $result->id }}">
                                    <button type="button" wire:click="addStory({{ $result->id }})" class="focus-ring flex min-h-11 w-full cursor-pointer items-center gap-3 rounded-control px-2 py-1.5 text-start hover:bg-surface-card">
                                        <flux:icon name="plus" variant="micro" class="size-4 shrink-0 text-ink-subtle" />
                                        <span class="min-w-0">
                                            <span class="block truncate text-meta text-ink">{{ $result->title }}</span>
                                            @if ($result->creators->isNotEmpty())
                                                <span class="block truncate text-legal text-ink-subtle">{{ $result->creators->pluck('name')->unique()->take(2)->implode(', ') }}</span>
                                            @endif
                                        </span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @elseif (mb_strlen(trim($search)) >= 2)
                        <p class="mt-2 text-meta text-ink-subtle" role="status">{{ __('No story every reader may see matches that.') }}</p>
                    @endif
                </div>

                @error('stories')<p class="mt-2 text-meta text-red-400">{{ $message }}</p>@enderror

                @if ($this->stories !== [])
                    <ol class="mt-3 divide-y divide-hairline">
                        @foreach ($this->stories as $index => $story)
                            <li wire:key="story-{{ $story->id }}" class="flex items-center gap-3 py-2">
                                <span class="w-5 text-end text-legal tabular-nums text-ink-subtle">{{ $index + 1 }}</span>
                                <span class="block aspect-[2/3] w-8 shrink-0 overflow-hidden rounded-control bg-surface-card">
                                    @if ($story->cover_url)
                                        <img src="{{ $story->cover_url }}" alt="" width="32" height="48" loading="lazy" class="size-full object-cover">
                                    @else
                                        <x-cover-fallback :title="$story->title" compact />
                                    @endif
                                </span>
                                <span class="min-w-0 flex-1 truncate text-meta text-ink">{{ $story->title }}</span>
                                <span class="flex shrink-0">
                                    <x-cta variant="secondary" icon="arrow-up" wire:click="moveStory({{ $index }}, -1)" :disabled="$index === 0" :aria-label="__('Move :title up', ['title' => $story->title])" class="bg-transparent! hover:bg-surface-card!" />
                                    <x-cta variant="secondary" icon="arrow-down" wire:click="moveStory({{ $index }}, 1)" :disabled="$loop->last" :aria-label="__('Move :title down', ['title' => $story->title])" class="bg-transparent! hover:bg-surface-card!" />
                                    <x-cta variant="secondary" icon="trash" wire:click="removeStory({{ $story->id }})" :aria-label="__('Remove :title', ['title' => $story->title])" class="bg-transparent! hover:bg-surface-card!" />
                                </span>
                            </li>
                        @endforeach
                    </ol>
                    <p class="mt-2 text-legal text-ink-subtle">{{ __('Use the arrows to change the order.') }}</p>
                @else
                    <p class="mt-3 text-meta text-ink-subtle">{{ __('No stories yet. Find one above to add it.') }}</p>
                @endif
            </div>

            <div class="mt-auto flex flex-wrap items-center justify-between gap-3 border-t border-hairline pt-5">
                @if ($editingId)
                    <x-cta variant="danger" type="button" wire:click="delete" wire:confirm="{{ __('Delete :name? Its stories are not deleted.', ['name' => $name]) }}">{{ __('Delete') }}</x-cta>
                @else
                    <span></span>
                @endif
                <div class="flex gap-3">
                    <x-cta variant="secondary" type="button" wire:click="cancel">{{ __('Cancel') }}</x-cta>
                    <x-cta variant="light" type="submit">{{ __('Save') }}</x-cta>
                </div>
            </div>
        </form>
    </flux:modal>
</section>
