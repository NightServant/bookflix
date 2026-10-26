<?php

use App\Discovery\Search\HybridSearch;
use App\Discovery\Search\SearchFilters;
use App\Models\Media;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The header row and results of <x-command-palette>: the search icon, the field and the close button on one borderless row, a
 * hairline, then hybrid search with no filters, the best 8 stories (title over its type and year). The hairline lights up while
 * the field has focus: the card is the focus context, so the field wears no ring of its own.
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

        {{-- The row is left alone when results re-render: a morph would drop the aria-controls the ui plugin sets after boot. --}}
        <div wire:ignore class="flex items-center gap-3 border-b border-hairline ps-4 pe-2 focus-within:border-accent-electric">
            <flux:icon name="magnifying-glass" class="size-5 shrink-0 text-ink-subtle" aria-hidden="true" />

            <input
                x-combobox:input
                x-bind:display-value="() => ''"
                wire:model.live.debounce.250ms="query"
                type="text"
                placeholder="{{ __('Search titles, authors and subjects') }}"
                autocomplete="off"
                autofocus
                class="h-14 min-w-0 flex-1 bg-transparent text-body text-ink outline-none placeholder:text-ink-subtle"
            >

            <button x-combobox:button type="button" hidden></button>

            <button
                type="button"
                @click="$dispatch('palette-close')"
                aria-label="{{ __('Close search') }}"
                class="focus-ring inline-flex size-11 shrink-0 items-center justify-center rounded-control text-ink-subtle hover:bg-surface-input hover:text-ink"
            >
                <flux:icon name="x-mark" class="size-5" />
            </button>
        </div>

        <div x-combobox:options class="max-h-[min(22rem,60vh)] overflow-y-auto p-2" style="display: none">
            @foreach ($this->results as $media)
                @php($url = route('stories.show', $media->slug))

                <a
                    x-combobox:option
                    wire:key="result-{{ $media->id }}"
                    href="{{ $url }}"
                    value="{{ $url }}"
                    @click.prevent
                    :class="{ 'bg-surface-input': $comboboxOption.isActive }"
                    class="flex min-h-14 flex-col justify-center rounded-control px-3 py-2 text-ink"
                >
                    <span class="text-body">{{ $media->title }}</span>
                    <span class="text-meta text-ink-subtle">{{ $media->mediaType->name }}@if ($media->publication_year) · {{ $media->publication_year }}@endif</span>
                </a>
            @endforeach
        </div>

        @if (trim($query) !== '')
            <p role="status" class="sr-only">{{ trans_choice('{0} No results|{1} 1 result|[2,*] :count results', $this->results->count()) }}</p>

            @if ($this->results->isEmpty())
                <p aria-hidden="true" class="px-4 py-4 text-meta text-ink-subtle">{{ __('No stories match “:query”.', ['query' => $query]) }}</p>
            @endif
        @else
            <p class="px-4 py-4 text-meta text-ink-subtle">{{ __('Type a title, an author or a subject.') }}</p>
        @endif
    </div>
</div>
