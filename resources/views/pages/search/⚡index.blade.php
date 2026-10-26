<?php

use App\Discovery\Search\HybridSearch;
use App\Discovery\Search\SearchFilters;
use App\Discovery\Search\SearchLogger;
use App\Enums\PublicationStatus;
use App\Models\Genre;
use App\Models\Media;
use App\Models\MediaType;
use App\Models\Theme;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Search')] class extends Component {
    use WithPagination;

    private const MIN_QUERY_LENGTH = 2;

    private const PER_PAGE = 24;

    /**
     * Every filter the page offers, empty. The controls bind to these keys, so they always exist.
     *
     * @var array<string, mixed>
     */
    private const NO_FILTERS = [
        'mediaTypes' => [],
        'statuses' => [],
        'genres' => [],
        'themes' => [],
        'languages' => [],
        'years' => [],
        'minRating' => '',
        'length' => '',
    ];

    #[Url]
    public string $q = '';

    /** @var array<string, mixed> */
    #[Url]
    public array $filters = [];

    #[Url]
    public string $view = 'grid';

    /** The last search this page logged: the one a refinement links back to and the one result clicks credit. */
    #[Locked]
    public ?int $lastQueryId = null;

    /** What the last logged search looked like, so the same settled search is logged once. */
    #[Locked]
    public string $loggedSignature = '';

    public function mount(): void
    {
        $this->filters = [...self::NO_FILTERS, ...Arr::only($this->filters, array_keys(self::NO_FILTERS))];
        $this->recordSearch();
    }

    public function updatedQ(): void
    {
        $this->resetPage();
        $this->recordSearch();
    }

    public function updatedFilters(): void
    {
        $this->resetPage();
        $this->recordSearch();
    }

    /**
     * Drop one chip: a single value of a list filter, or the whole of a single-value one.
     */
    public function removeFilter(string $group, ?string $value = null): void
    {
        if (! array_key_exists($group, self::NO_FILTERS)) {
            return;
        }

        $this->filters[$group] = is_array(self::NO_FILTERS[$group]) && $group !== 'years'
            ? array_values(array_filter(Arr::wrap($this->filters[$group] ?? []), fn (mixed $item): bool => (string) $item !== $value))
            : self::NO_FILTERS[$group];

        $this->updatedFilters();
    }

    public function clearFilters(): void
    {
        $this->filters = self::NO_FILTERS;
        $this->updatedFilters();
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
     * The filters as the search sees them: only the keys the page offers, with the year slider's two ends mapped to a range
     * (a slider left at its limits filters nothing).
     */
    private function searchFilters(): SearchFilters
    {
        $input = Arr::only($this->filters, array_keys(self::NO_FILTERS));
        [$from, $to] = array_pad(array_values(Arr::wrap($input['years'] ?? [])), 2, null);
        [$lowest, $highest] = $this->facets['years'];

        return SearchFilters::fromArray([
            ...$input,
            'yearFrom' => (int) $from > $lowest ? $from : null,
            'yearTo' => $to !== null && (int) $to < $highest ? $to : null,
        ]);
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
            : $search->browse($filters, self::PER_PAGE);

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
     * The extra query-string parameters that credit a click on a result to the search that showed it.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function resultLinkQuery(): array
    {
        return $this->lastQueryId === null ? [] : ['sq' => $this->lastQueryId];
    }

    /**
     * What each control offers.
     *
     * @return array{types: array<string, string>, statuses: array<string, string>, genres: array<string, string>, themes: array<string, string>, languages: array<string, string>, lengths: array<string, string>, ratings: array<string, string>, years: array{0: int, 1: int}}
     */
    #[Computed]
    public function facets(): array
    {
        $years = Media::query()->unmerged()->selectRaw('min(media.publication_year) as lowest, max(media.publication_year) as highest')->first();

        return [
            'types' => MediaType::query()->orderBy('name')->pluck('name', 'slug')->all(),
            'statuses' => collect(PublicationStatus::cases())
                ->reject(fn (PublicationStatus $status): bool => $status === PublicationStatus::Unknown)
                ->mapWithKeys(fn (PublicationStatus $status): array => [$status->value => __(ucfirst($status->value))])
                ->all(),
            'genres' => Genre::query()->orderBy('name')->pluck('name', 'slug')->all(),
            'themes' => Theme::query()->orderBy('name')->pluck('name', 'slug')->all(),
            'languages' => collect(config('bookflix.languages'))
                ->mapWithKeys(fn (string $code): array => [$code => class_exists(\Locale::class) ? ucfirst(\Locale::getDisplayLanguage($code, app()->getLocale())) : strtoupper($code)])
                ->all(),
            'lengths' => ['short' => __('Short'), 'medium' => __('Medium'), 'long' => __('Long')],
            'ratings' => ['6' => __('3 stars and up'), '7' => __('3.5 stars and up'), '8' => __('4 stars and up'), '9' => __('4.5 stars and up')],
            'years' => [(int) ($years?->lowest ?? 1900), (int) ($years?->highest ?? now()->year)],
        ];
    }

    /**
     * The active filters as chips: the filter it belongs to, the value to remove (null for a single-value filter) and the label to show.
     *
     * @return list<array{group: string, value: string|null, label: string}>
     */
    #[Computed]
    public function chips(): array
    {
        $filters = $this->searchFilters();
        $facets = $this->facets;
        $chips = [];

        foreach ([
            'mediaTypes' => [$filters->mediaTypes, $facets['types']],
            'statuses' => [$filters->statuses, $facets['statuses']],
            'genres' => [$filters->genres, $facets['genres']],
            'themes' => [$filters->themes, $facets['themes']],
            'languages' => [$filters->languages, $facets['languages']],
        ] as $group => [$values, $labels]) {
            foreach ($values as $value) {
                $chips[] = ['group' => $group, 'value' => $value, 'label' => $labels[$value] ?? $value];
            }
        }

        if ($filters->yearFrom !== null || $filters->yearTo !== null) {
            $chips[] = ['group' => 'years', 'value' => null, 'label' => ($filters->yearFrom ?? $facets['years'][0]).' – '.($filters->yearTo ?? $facets['years'][1])];
        }

        if ($filters->minRating !== null) {
            $chips[] = ['group' => 'minRating', 'value' => null, 'label' => $facets['ratings'][(string) (int) $filters->minRating] ?? __(':stars stars and up', ['stars' => $filters->minRating / 2])];
        }

        if ($filters->length !== null) {
            $chips[] = ['group' => 'length', 'value' => null, 'label' => $facets['lengths'][$filters->length]];
        }

        return $chips;
    }
}; ?>

<section class="py-12">
    <h1 class="sr-only">{{ __('Search') }}</h1>

    <x-search-panel wire:model.live.debounce.300ms="q" :placeholder="__('Titles, authors, series and subjects')" />

    <div class="mt-8 lg:grid lg:grid-cols-[16rem_minmax(0,1fr)] lg:gap-10">
        <aside aria-labelledby="search-filters-title" class="hidden lg:block">
            <h2 id="search-filters-title" class="text-lead text-ink">{{ __('Filters') }}</h2>

            <div class="mt-4">
                @include('pages.search.filters', ['facets' => $this->facets])
            </div>
        </aside>

        <div class="min-w-0">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p role="status" data-test="result-count" class="text-meta text-ink-subtle">
                    @if ($this->isSearching)
                        {{ trans_choice('{0} No stories|{1} 1 story|[2,*] :count stories', $this->results->total()) }}
                    @endif
                </p>

                <div class="flex items-center gap-3">
                    <x-filter-sheet>
                        @include('pages.search.filters', ['facets' => $this->facets])
                    </x-filter-sheet>

                    <x-view-toggle wire:model.live="view" />
                </div>
            </div>

            @if ($this->chips !== [])
                <ul aria-label="{{ __('Active filters') }}" class="mt-4 flex flex-wrap items-center gap-2">
                    @foreach ($this->chips as $chip)
                        <li wire:key="chip-{{ $chip['group'] }}-{{ $chip['value'] }}" class="inline-flex items-center rounded-full bg-surface-input ps-4 text-meta text-ink">
                            <span>{{ $chip['label'] }}</span>
                            <button
                                type="button"
                                wire:click="removeFilter('{{ $chip['group'] }}', @js($chip['value']))"
                                aria-label="{{ __('Remove filter :label', ['label' => $chip['label']]) }}"
                                class="focus-ring inline-flex size-11 items-center justify-center rounded-full text-ink-subtle hover:text-ink"
                            >
                                <flux:icon name="x-mark" class="size-4" />
                            </button>
                        </li>
                    @endforeach

                    <li wire:key="chips-clear">
                        <button type="button" wire:click="clearFilters" class="focus-ring inline-flex min-h-11 items-center rounded-control px-3 text-meta text-link hover:underline">{{ __('Clear all') }}</button>
                    </li>
                </ul>
            @endif

            @if ($this->results->isNotEmpty())
                @if ($this->layout === 'list')
                    <ul data-view="list" class="mt-6 flex flex-col gap-4">
                        @foreach ($this->results as $media)
                            <li wire:key="result-{{ $media->id }}">
                                <x-result-row :media="$media" :query="$this->resultLinkQuery" />
                            </li>
                        @endforeach
                    </ul>
                @else
                    <ul data-view="grid" class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                        @foreach ($this->results as $media)
                            <li wire:key="result-{{ $media->id }}">
                                <x-cover-tile :media="$media" :query="$this->resultLinkQuery" badge fluid />
                            </li>
                        @endforeach
                    </ul>
                @endif

                <flux:pagination :paginator="$this->results" class="mt-8" />
            @elseif ($this->isSearching)
                <div class="mt-12 max-w-prose">
                    <h2 class="text-section text-ink">{{ __('No stories found') }}</h2>

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

                    <x-cta variant="secondary" :href="route('explore.index')" wire:navigate class="mt-6">{{ __('Explore the Library') }}</x-cta>
                </div>
            @else
                <p class="mt-12 max-w-prose text-body text-ink-subtle">{{ __('Search by title, author, series or subject. Type at least two letters, or pick a filter to browse.') }}</p>
            @endif
        </div>
    </div>
</section>
