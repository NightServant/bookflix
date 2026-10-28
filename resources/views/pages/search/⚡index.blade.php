<?php

use App\Discovery\Search\HybridSearch;
use App\Discovery\Search\SearchResults;
use App\Discovery\Search\SearchLogger;
use App\Livewire\FilteredPage;
use App\Models\Media;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

new #[Title('Search')] class extends FilteredPage {
    private const MIN_QUERY_LENGTH = 2;

    private const PER_PAGE = 24;

    #[Url]
    public string $q = '';

    #[Url]
    public string $view = 'grid';

    /** The last search this page logged: the one a refinement links back to. */
    #[Locked]
    public ?int $lastQueryId = null;

    /** What the last logged search looked like, so the same settled search is logged once. */
    #[Locked]
    public string $loggedSignature = '';

    public function mount(): void
    {
        $this->q = $this->cleanQuery($this->q);
        $this->initFilters();
        $this->recordSearch();
    }

    public function updatedQ(): void
    {
        $this->q = $this->cleanQuery($this->q);
        $this->resetPage();
        $this->recordSearch();
    }

    protected function filtersChanged(): void
    {
        $this->recordSearch();
    }

    /**
     * Invalid UTF-8 replaced and the length capped, once, at the boundary: what the page logs, shows and searches is always storable.
     */
    private function cleanQuery(string $text): string
    {
        return mb_substr(mb_scrub($text), 0, HybridSearch::MAX_QUERY_LENGTH);
    }

    /**
     * Log the search once it has settled (a new text or new filters, never a page turn or a re-render) and chain it to the one before.
     * Text under two characters is not a search: it is not run and not logged, and it ends the chain.
     */
    private function recordSearch(): void
    {
        if (! $this->hasQuery) {
            $this->lastQueryId = null;
            $this->loggedSignature = '';

            return;
        }

        $logger = app(SearchLogger::class);
        $filters = $this->searchFilters();
        $signature = json_encode([$logger->normalize($this->q), $filters->toArray()], JSON_THROW_ON_ERROR);

        if ($signature === $this->loggedSignature) {
            return;
        }

        if ($this->throttled) {
            return;
        }

        $this->lastQueryId = $logger->log(trim($this->q), $filters, $this->results->total(), auth()->user(), $this->lastQueryId)->id;
        $this->loggedSignature = $signature;
    }

    /**
     * Whether the text is long enough to be a search.
     */
    #[Computed]
    public function hasQuery(): bool
    {
        return mb_strlen(trim($this->q)) >= self::MIN_QUERY_LENGTH;
    }

    /**
     * 'list' or 'grid'; anything else in the URL is the grid.
     */
    #[Computed]
    public function layout(): string
    {
        return $this->view === 'list' ? 'list' : 'grid';
    }

    /**
     * Whether there is anything to search for: at least two characters of text, or at least one filter.
     */
    #[Computed]
    public function isSearching(): bool
    {
        return $this->hasQuery || ! $this->searchFilters()->isEmpty();
    }

    /**
     * One page of results with what the layout renders already loaded (lazy loading is off). Text searches rank; filters alone browse.
     *
     * @return LengthAwarePaginator<int, Media>
     */
    #[Computed]
    public function results(): LengthAwarePaginator
    {
        if (! $this->isSearching) {
            return new LengthAwarePaginator([], 0, self::PER_PAGE);
        }

        $search = app(HybridSearch::class);
        $filters = $this->searchFilters();
        $fetch = fn (): LengthAwarePaginator => $this->hasQuery
            ? $search->search(trim($this->q), $filters, auth()->user(), self::PER_PAGE)
            : $search->browse($filters, self::PER_PAGE, auth()->user());

        $page = $fetch();

        if ($page->isEmpty() && $page->currentPage() > 1 && $page->total() > 0) {
            $this->setPage($page->lastPage());
            $page = $fetch();
        }

        (new Collection($page->items()))->load($this->layout === 'list'
            ? ['mediaType', 'publicPrimaryDewey.deweyClass', 'creators', 'genres']
            : ['mediaType']);

        return $page;
    }

    /**
     * Whether the reader has searched too often to be served this page.
     */
    #[Computed]
    public function throttled(): bool
    {
        return $this->results instanceof SearchResults && $this->results->throttled;
    }
}; ?>

<section class="pb-12">
    <x-page-header
        :title="$this->hasQuery ? __('Results for “:q”', ['q' => trim($q)]) : __('Search')"
        :description="__('Search titles, authors, series and subjects. Narrow the results with the filters.')"
    />

    <x-search-panel wire:model.live.debounce.300ms="q" :browse="false" :placeholder="__('Titles, authors, series and subjects')" class="mt-6" />

    <x-filter-islands :panel="fn () => $this->panel" :years="$this->facets['years']" />

    <div x-data="filterPanel(@js($this->panelConfig))" x-on:filter-sheet-close="discard()" class="mt-8 lg:grid lg:grid-cols-[20rem_minmax(0,1fr)] lg:gap-12">
        <aside aria-labelledby="search-filters-title" class="hidden self-start lg:sticky lg:top-20 lg:block">
            <div class="flex min-h-11 items-center justify-between gap-4">
                <h2 id="search-filters-title" class="text-lead text-ink">{{ __('Filters') }}</h2>

                <button type="button" x-show="activeCount > 0 || dirty" style="display: none" @click="clear()" class="focus-ring -mx-2 inline-flex min-h-11 items-center gap-1 rounded-control px-2 text-meta text-link hover:underline"><flux:icon name="x-mark" variant="micro" class="size-4" />{{ __('Clear all') }}</button>
            </div>

            <div wire:ignore x-html="desktop ? panelHtml : ''" class="mt-2 max-h-[calc(100dvh-9rem)] overflow-y-auto overscroll-contain pe-2"></div>
        </aside>

        <div class="min-w-0">
            <div class="flex min-h-11 flex-wrap items-center justify-between gap-3">
                <p role="status" data-test="result-count" class="text-meta tabular-nums text-ink-subtle">
                    @if ($this->isSearching && ! $this->throttled)
                        @if ($this->results instanceof SearchResults && $this->results->hasMore)
                            {{ __(':count+ stories', ['count' => $this->results->total()]) }}
                        @else
                            {{ trans_choice('{0} No stories|{1} 1 story|[2,*] :count stories', $this->results->total()) }}
                        @endif
                    @endif
                </p>

                <div class="flex items-center gap-3">
                    <x-filter-sheet>
                        <x-slot:badge>
                            <span x-show="activeCount > 0" style="display: none" class="tabular-nums text-ink-subtle" wire:ignore><span aria-hidden="true" class="mx-1.5">·</span><span x-text="activeCount"></span><span class="sr-only"> {{ __('applied') }}</span></span>
                        </x-slot:badge>

                        <div wire:ignore x-html="desktop ? '' : panelHtml"></div>

                        <x-slot:footer>
                            <div class="flex items-center gap-3">
                                <button type="button" @click="clear()" class="focus-ring inline-flex min-h-11 items-center rounded-control px-3 text-body text-link hover:underline">{{ __('Clear all') }}</button>
                                <flux:modal.close class="flex-1">
                                    <x-cta icon="check" class="w-full" @click="apply()">{{ __('Apply filters') }}</x-cta>
                                </flux:modal.close>
                            </div>
                        </x-slot:footer>
                    </x-filter-sheet>

                    <x-view-toggle wire:model.live="view" />
                </div>
            </div>

            @include('pages.search.chips')

            <div wire:loading.delay.shortest.class="opacity-60" class="motion-safe:transition-opacity">
            @if ($this->results->isNotEmpty())
                {{-- Every story of one format makes the format badge noise: it is shown only when the page mixes formats. --}}
                @php $mixed = collect($this->results->items())->pluck('media_type_id')->unique()->count() > 1; @endphp

                @if ($this->layout === 'list')
                    <ul data-view="list" class="mt-6 flex flex-col gap-4">
                        @foreach ($this->results as $media)
                            <li wire:key="result-{{ $media->id }}">
                                <x-result-row :media="$media" />
                            </li>
                        @endforeach
                    </ul>
                @else
                    <ul data-view="grid" class="mt-6 grid grid-cols-2 gap-x-4 gap-y-6 sm:grid-cols-[repeat(auto-fill,minmax(11rem,1fr))]">
                        @foreach ($this->results as $media)
                            <li wire:key="result-{{ $media->id }}">
                                <x-cover-tile :media="$media" :badge="$mixed" fluid />
                            </li>
                        @endforeach
                    </ul>
                @endif

                <x-pager :paginator="$this->results" class="mt-8" />
            @elseif ($this->throttled)
                <x-empty-state :title="__('Searching a little too fast')" class="mt-12">
                    <p class="mt-2 text-body text-ink-subtle">{{ __('Give it a moment, then try again. Your search is still here.') }}</p>

                    <div class="mt-6 flex flex-wrap gap-3">
                        <x-cta variant="secondary" icon="arrow-path" wire:click="$refresh">{{ __('Try again') }}</x-cta>
                    </div>
                </x-empty-state>
            @elseif ($this->isSearching)
                <x-empty-state :title="__('No stories found')" class="mt-12">

                    @if ($this->hasQuery)
                        <p class="mt-2 text-body text-ink-subtle">{{ __('Nothing matched “:query”.', ['query' => trim($q)]) }}</p>
                    @endif

                    <p class="mt-4 text-body text-ink">{{ __('Try') }}</p>
                    <ul class="mt-2 list-disc ps-6 text-body text-ink-subtle">
                        <li>{{ __('fewer or different words,') }}</li>
                        <li>{{ __('checking the spelling,') }}</li>
                        @if ($this->chips !== [])
                            <li>{{ __('removing a filter,') }}</li>
                        @endif
                        <li>{{ __('or browsing the Library by subject.') }}</li>
                    </ul>

                    <div class="mt-6 flex flex-wrap gap-3">
                        <x-cta variant="secondary" icon="building-library" :href="route('explore.index')" wire:navigate>{{ __('Explore the Library') }}</x-cta>
                    </div>
                </x-empty-state>
            @else
                <p class="mt-12 max-w-prose text-body text-ink-subtle">{{ __('Search by title, author, series or subject. Type at least two letters, or pick a filter to browse.') }}</p>
            @endif
            </div>
        </div>
    </div>
</section>
