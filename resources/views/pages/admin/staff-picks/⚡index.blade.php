<?php

use App\Enums\Visibility;
use App\Models\Collection;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Staff picks: the public collections readers have built, each with a switch that makes it an editorial collection (a themed row for every reader
 * on Discover and Home, see EditorialCollections). Only a public collection can be picked; a pick already made stays listed so it can be turned off,
 * and it ends on its own when the owner makes the collection private (see Collection::booted). Private collections are never listed here.
 */
new #[Title('Staff picks')] class extends Component {
    use WithPagination;

    #[Url(as: 'show')]
    public string $show = 'all';

    public function boot(): void
    {
        Gate::authorize('access-admin');
    }

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function filters(): array
    {
        return ['all' => __('All public'), 'picks' => __('Staff picks')];
    }

    /**
     * The most recently changed first. Picks are not sorted to the top: a switch would then move its row away from under the pointer, onto another
     * page. Only the columns the table shows are read; the owner is named, never their email.
     *
     * @return LengthAwarePaginator<int, Collection>
     */
    #[Computed]
    public function collections(): LengthAwarePaginator
    {
        return $this->listed()
            ->when($this->show === 'picks', fn (Builder $query) => $query->where('is_editorial', true))
            ->select(['id', 'user_id', 'name', 'description', 'visibility', 'is_editorial', 'updated_at'])
            ->with('user:id,name')
            ->withCount('items')
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(25);
    }

    #[Computed]
    public function picks(): int
    {
        return Collection::query()->where('is_editorial', true)->count();
    }

    public function toggle(int $collectionId): void
    {
        Gate::authorize('access-admin');

        $collection = $this->listed()->findOrFail($collectionId);
        $pick = ! $collection->is_editorial;

        // A collection its owner keeps private can be taken off the list but never put on it.
        abort_if($pick && $collection->visibility !== Visibility::Public, 404);

        // The owner's "Updated" date stays theirs: a pick is not an edit of the collection.
        $collection->timestamps = false;
        $collection->is_editorial = $pick;
        $collection->save();

        unset($this->collections, $this->picks);

        Flux::toast(variant: 'success', text: $pick
            ? __(':name is now a staff pick.', ['name' => $collection->name])
            : __(':name is no longer a staff pick.', ['name' => $collection->name]));
    }

    /**
     * What this page may list and change: public collections of active readers, and every collection already picked.
     *
     * @return Builder<Collection>
     */
    private function listed(): Builder
    {
        return Collection::query()->where(fn (Builder $query) => $query
            ->where('is_editorial', true)
            ->orWhere(fn (Builder $public) => $public
                ->where('visibility', Visibility::Public->value)
                ->whereHas('user', fn (Builder $owner) => $owner->active())));
    }
}; ?>

<section class="py-8">
    <x-admin-header :title="__('Staff picks')" :description="__('Public collections readers have built. Turn one on to show it to every reader as a themed row on Discover and Home. A pick ends if its owner makes the collection private.')" />

    @php($shown = array_key_exists($show, $this->filters) ? $show : 'all')

    <nav aria-label="{{ __('Show') }}" class="mt-6 flex flex-wrap gap-2">
        @foreach ($this->filters as $value => $label)
            <x-chip wire:key="show-{{ $value }}" :href="route('admin.staff-picks', ['show' => $value])" wire:click.prevent="$set('show', '{{ $value }}')" :active="$shown === $value" class="min-h-11 px-4">{{ $label }}</x-chip>
        @endforeach
    </nav>

    @if ($this->collections->isEmpty())
        @if ($shown === 'picks')
            <x-admin-empty class="mt-6 rounded-card bg-surface-card" icon="star" :title="__('No staff picks yet')" :description="__('Turn on a collection under All public to show it to every reader.')" />
        @else
            <x-admin-empty class="mt-6 rounded-card bg-surface-card" icon="star" :title="__('No public collections yet')" :description="__('When a reader makes a collection public it appears here, ready to be made a staff pick.')" />
        @endif
    @else
        {{-- A table from md up; below it each collection is a card, each cell named by its data-label (the header row is hidden there). --}}
        <flux:table class="mt-6 table-auto! max-md:block max-md:whitespace-normal! max-md:[&_tbody]:block max-md:[&_thead]:hidden max-md:[&_tr]:block max-md:[&_td]:block max-md:[&_td]:border-0 max-md:[&_td]:px-0">
            <flux:table.columns>
                <flux:table.column>{{ __('Collection') }}</flux:table.column>
                <flux:table.column>{{ __('Owner') }}</flux:table.column>
                <flux:table.column>{{ __('Stories') }}</flux:table.column>
                <flux:table.column>{{ __('Updated') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Staff pick') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->collections as $collection)
                    @php($cell = 'max-md:flex! max-md:gap-4 max-md:py-1 max-md:before:w-24 max-md:before:shrink-0 max-md:before:text-ink-subtle max-md:before:content-[attr(data-label)]')
                    <flux:table.row wire:key="collection-{{ $collection->id }}" class="max-md:mb-4 max-md:rounded-card max-md:bg-surface-card max-md:p-4">
                        <flux:table.cell class="md:max-w-96 md:whitespace-normal!">
                            <span class="block text-body text-ink">{{ $collection->name }}</span>
                            @if ($collection->description)
                                <span class="block line-clamp-1 text-meta text-ink-subtle">{{ $collection->description }}</span>
                            @endif
                            @if ($collection->visibility !== \App\Enums\Visibility::Public)
                                <span class="mt-1 block text-legal text-ink-subtle">{{ __('Private: turning it off removes it from this list.') }}</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell data-label="{{ __('Owner') }}" class="{{ $cell }}">{{ $collection->user?->name }}</flux:table.cell>
                        <flux:table.cell data-label="{{ __('Stories') }}" class="{{ $cell }} tabular-nums">{{ Number::format($collection->items_count) }}</flux:table.cell>
                        <flux:table.cell data-label="{{ __('Updated') }}" class="{{ $cell }}">{{ $collection->updated_at?->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell data-label="{{ __('Staff pick') }}" class="{{ $cell }} max-md:items-center">
                            <div class="flex items-center gap-3 md:justify-end max-md:flex-1 max-md:justify-end">
                                <span class="text-meta {{ $collection->is_editorial ? 'text-ink' : 'text-ink-subtle' }}">{{ $collection->is_editorial ? __('Shown to readers') : __('Off') }}</span>
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked="{{ $collection->is_editorial ? 'true' : 'false' }}"
                                    aria-label="{{ __('Staff pick: :name', ['name' => $collection->name]) }}"
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
                @if ($shown === 'picks')
                    {{ trans_choice(':count staff pick|:count staff picks', $this->collections->total(), ['count' => Number::format($this->collections->total())]) }} · {{ __('shown to every reader on Discover and Home') }}
                @else
                    {{ trans_choice(':count collection|:count collections', $this->collections->total(), ['count' => Number::format($this->collections->total())]) }} · {{ trans_choice(':count staff pick|:count staff picks', $this->picks, ['count' => Number::format($this->picks)]) }}
                @endif
            </p>
            <x-pager :paginator="$this->collections" class="justify-end" />
        </div>
    @endif
</section>
