<?php

use App\Discovery\Search\HybridSearch;
use App\Discovery\Search\SearchFilters;
use App\Models\Media;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The search field and results of <x-command-palette>: hybrid search with no filters, the best 8 stories.
 */
new class extends Component {
    public string $query = '';

    /**
     * @return Collection<int, Media>
     */
    #[Computed]
    public function results(): Collection
    {
        $page = app(HybridSearch::class)->search($this->query, new SearchFilters, auth()->user(), 8);

        return (new Collection($page->items()))->load('mediaType');
    }
}; ?>

<div
    x-data="{ picked: null }"
    x-init="$watch('picked', (url) => {
        if (! url) return;

        picked = null;
        $wire.query = '';
        $dispatch('palette-close');
        Livewire.navigate(url);
    })"
>
    <div
        x-combobox
        x-model="picked"
        @keydown.escape.capture="$combobox.isOpen || $dispatch('palette-close')"
    >
        <label x-combobox:label class="sr-only">{{ __('Search the Library') }}</label>

        {{-- The field is left alone when results re-render: a morph would drop the aria-controls the ui plugin sets after boot. --}}
        <div wire:ignore>
            <flux:input
                x-combobox:input
                x-bind:display-value="() => ''"
                wire:model.live.debounce.250ms="query"
                icon="magnifying-glass"
                :placeholder="__('Search titles, authors and subjects')"
                autocomplete="off"
                autofocus
                class:input="h-11! rounded-control!"
            />

            <button x-combobox:button type="button" hidden></button>
        </div>

        <div x-combobox:options class="mt-2 max-h-80 overflow-y-auto" style="display: none">
            @foreach ($this->results as $media)
                @php($url = route('stories.show', $media->slug))

                <a
                    x-combobox:option
                    wire:key="result-{{ $media->id }}"
                    href="{{ $url }}"
                    value="{{ $url }}"
                    @click.prevent
                    :class="{ 'bg-surface-input': $comboboxOption.isActive }"
                    class="flex min-h-11 flex-col justify-center rounded-control px-3 py-2 text-ink"
                >
                    <span class="text-body">{{ $media->title }}</span>
                    <span class="text-legal text-ink-subtle">{{ $media->mediaType->name }}@if ($media->publication_year) · {{ $media->publication_year }}@endif</span>
                </a>
            @endforeach
        </div>

        @if (trim($query) !== '')
            <p role="status" class="sr-only">{{ trans_choice('{0} No results|{1} 1 result|[2,*] :count results', $this->results->count()) }}</p>

            @if ($this->results->isEmpty())
                <p aria-hidden="true" class="mt-2 px-3 py-2 text-meta text-ink-subtle">{{ __('No stories match “:query”.', ['query' => $query]) }}</p>
            @endif
        @endif
    </div>
</div>
