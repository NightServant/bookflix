<?php

use App\Models\Media;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Media')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public bool $trashed = false;

    public function boot(): void
    {
        Gate::authorize('access-admin');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTrashed(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Media>
     */
    #[Computed]
    public function media(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return Media::query()
            ->when($this->trashed, fn ($query) => $query->onlyTrashed())
            ->when($term !== '', fn ($query) => $query->where('title', 'ilike', '%'.addcslashes($term, '%_\\').'%'))
            ->with('mediaType')
            ->orderBy('title')
            ->orderBy('id')
            ->paginate(25);
    }
}; ?>

<section class="py-12">
    <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('admin.index')" wire:navigate>{{ __('Admin') }}</flux:button>
    <h1 class="mt-2 text-section text-ink">{{ __('Media') }}</h1>

    <div class="mt-6 flex flex-wrap items-center gap-4">
        <flux:input class="max-w-sm" type="search" icon="magnifying-glass" wire:model.live.debounce.300ms="search" :placeholder="__('Search titles')" />
        <flux:checkbox wire:model.live="trashed" :label="__('Show removed and merged')" />
    </div>

    <flux:table class="mt-6" :paginate="$this->media">
        <flux:table.columns>
            <flux:table.column>{{ __('Title') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Year') }}</flux:table.column>
            <flux:table.column>{{ __('Locked fields') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->media as $item)
                <flux:table.row wire:key="media-{{ $item->id }}">
                    <flux:table.cell variant="strong">{{ $item->title }}</flux:table.cell>
                    <flux:table.cell>{{ $item->mediaType->name }}</flux:table.cell>
                    <flux:table.cell>{{ $item->publication_year }}</flux:table.cell>
                    <flux:table.cell>{{ implode(', ', $item->locked_fields) ?: '—' }}</flux:table.cell>
                    <flux:table.cell class="text-end">
                        @if ($item->trashed())
                            <flux:badge size="sm" color="zinc">{{ $item->merged_into_id ? __('Merged') : __('Removed') }}</flux:badge>
                        @else
                            <flux:button size="sm" variant="filled" icon="pencil-square" icon:variant="outline" :href="route('admin.media.edit', $item)" wire:navigate>{{ __('Edit') }}</flux:button>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-ink-muted">{{ __('No media found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
