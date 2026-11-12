<?php

use App\Discovery\Search\HybridSearch;
use App\Discovery\Search\QueryIntent;
use App\Discovery\Search\QueryParser;
use App\Discovery\Search\SearchResults;
use App\Discovery\Search\SearchLogger;
use App\Livewire\FilteredPage;
use App\Models\DeweyClass;
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

    /**
     * The values of the reader's words the reader removed from this search, as `group => values` in the shape of a QueryIntent. Only the page sets it
     * (never the browser), only to values the search offered, and it is emptied whenever the text changes.
     *
     * @var array<string, list<string>>
     */
    #[Locked]
    public array $dismissed = [];

    public function mount(): void
    {
        $this->q = $this->cleanQuery($this->q);
        $this->initFilters();
        $this->recordSearch();
    }

    public function updatedQ(): void
    {
        $this->q = $this->cleanQuery($this->q);
        $this->dismissed = [];
        $this->resetPage();
        $this->recordSearch();
    }

    /**
     * Take one value the search understood out of this search ("Remove Manga"). Only a value that is applied right now can go, and nothing is ever added.
     */
    public function dismissIntent(string $group, mixed $value): void
    {
        $applied = $this->results instanceof SearchResults ? $this->results->appliedIntent : [];

        if (! is_string($value) || ! in_array($value, (array) ($applied[$group] ?? []), true)) {
            return;
        }

        $this->dismissed[$group] = [...($this->dismissed[$group] ?? []), $value];
        $this->searchChanged();
    }

    /**
     * Act on a chip of "Narrow by": a value the reader removed comes back into the search, any other becomes one of the reader's own filters. Only a chip on offer counts.
     */
    public function addIntentFilter(string $group, mixed $value): void
    {
        $offered = collect($this->intentChips['suggested'])->contains(fn (array $chip): bool => $chip['group'] === $group && $chip['value'] === $value);

        if (! $offered || ! is_string($value)) {
            return;
        }

        if (in_array($value, $this->dismissed[$group] ?? [], true)) {
            $this->dismissed[$group] = array_values(array_diff($this->dismissed[$group], [$value]));
            $this->searchChanged();

            return;
        }

        $this->filters[$group] = [...($this->filters[$group] ?? []), $value];
        $this->forgetSearch();
        $this->updatedFilters();
    }

    private function searchChanged(): void
    {
        $this->forgetSearch();
        $this->resetPage();
        $this->recordSearch();
    }

    /**
     * What was worked out from the text and the filters before this change is stale.
     */
    private function forgetSearch(): void
    {
        unset($this->results, $this->searchIntent, $this->intentChips, $this->chips, $this->isSearching, $this->throttled);
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
        $intent = $this->searchIntent;
        $signature = json_encode([$logger->normalize($this->q), $filters->toArray(), $intent?->toArray()], JSON_THROW_ON_ERROR);

        if ($signature === $this->loggedSignature) {
            return;
        }

        if ($this->throttled) {
            return;
        }

        $this->lastQueryId = $logger->log(trim($this->q), $filters, $this->results->total(), auth()->user(), $this->lastQueryId, $intent)->id;
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
            ? $search->search(trim($this->q), $filters, auth()->user(), self::PER_PAGE, $this->searchIntent)
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
     * What the reader's words name in the taxonomy, less what the reader removed: the intent the search runs with. Null without a text search.
     * The parser gates it for the reader; what is removed is only ever taken out of it, and it is cleaned again on the way.
     */
    #[Computed]
    public function searchIntent(): ?QueryIntent
    {
        if (! $this->hasQuery) {
            return null;
        }

        $intent = app(QueryParser::class)->parse(trim($this->q), auth()->user())->toArray();

        foreach (['mediaTypes', 'genres', 'themes'] as $group) {
            $intent[$group] = array_values(array_diff($intent[$group], $this->dismissed[$group] ?? []));
        }

        $intent['deweyCode'] = in_array($intent['deweyCode'], $this->dismissed['deweyCode'] ?? [], true) ? null : $intent['deweyCode'];

        return QueryIntent::fromArray($intent);
    }

    /**
     * The chips for what the search understood: `applied` ones narrowed these results, `suggested` ones can be added (a value the reader removed is
     * offered again; a Dewey class is offered only for that, since the reader's filters have no class). Each is `group`, `value` and its `text`. A value with
     * no label for this reader is left out. None are offered when nothing was found.
     *
     * @return array{applied: list<array{group: string, value: string, text: string}>, suggested: list<array{group: string, value: string, text: string}>}
     */
    #[Computed]
    public function intentChips(): array
    {
        $none = ['applied' => [], 'suggested' => []];

        if (! $this->results instanceof SearchResults || $this->results->isEmpty()) {
            return $none;
        }

        $labels = $this->facets;
        $filters = $this->searchFilters();
        $text = fn (string $group, string $value): ?string => $group === 'deweyCode'
            ? DeweyClass::where('code', $value)->value('name')
            : ($labels[$group][$value] ?? null);
        $collect = function (array $values) use ($text): array {
            $chips = [];

            foreach (['mediaTypes', 'genres', 'themes', 'deweyCode'] as $group) {
                foreach (array_unique((array) ($values[$group] ?? [])) as $value) {
                    $label = $text($group, $value);
                    $label === null || $chips[] = ['group' => $group, 'value' => $value, 'text' => $label];
                }
            }

            return $chips;
        };

        $suggested = $this->results->suggestedIntent;
        unset($suggested['deweyCode']);

        foreach ($this->dismissed as $group => $values) {
            // A group the reader filters by hand is theirs; the words no longer speak for it.
            if ($filters->{$group} === [] || $filters->{$group} === null) {
                $suggested[$group] = [...(array) ($suggested[$group] ?? []), ...$values];
            }
        }

        return ['applied' => $collect($this->results->appliedIntent), 'suggested' => $collect($suggested)];
    }

    /**
     * Whether the semantic leg found stories the words did not (see SearchResults): the page says so once, quietly.
     */
    #[Computed]
    public function includesMeaningMatches(): bool
    {
        return $this->results instanceof SearchResults && $this->results->includesSemanticMatches && $this->results->isNotEmpty();
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

    @include('pages.search.intent-chips')

    <div x-data="filterPanel(@js($this->panelConfig))" x-on:filter-sheet-close="discard()" class="mt-8 lg:grid lg:grid-cols-[20rem_minmax(0,1fr)] lg:gap-12">
        <aside aria-labelledby="search-filters-title" class="hidden self-start lg:sticky lg:top-20 lg:block">
            <div class="flex min-h-11 items-center justify-between gap-4">
                <h2 id="search-filters-title" class="text-lead text-ink">{{ __('Filters') }}</h2>

                <button type="button" x-show="activeCount > 0 || dirty" style="display: none" @click="clear()" class="focus-ring -mx-2 inline-flex min-h-11 items-center gap-1 rounded-control px-2 text-meta text-link hover:underline"><flux:icon name="x-mark" variant="micro" class="size-4" />{{ __('Clear all') }}</button>
            </div>

            <div wire:ignore data-filter-mount x-html="desktop ? panelHtml : ''" class="mt-2 max-h-[calc(100dvh-9rem)] overflow-y-auto overscroll-contain pe-2"></div>
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

                        <div wire:ignore data-filter-mount x-html="desktop ? '' : panelHtml"></div>

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

            @if ($this->includesMeaningMatches)
                <p class="mt-2 max-w-prose text-meta text-ink-subtle">{{ __('Includes stories that match the meaning of your search, not only its words.') }}</p>
            @endif

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
