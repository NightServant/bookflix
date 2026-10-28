<?php

use App\Access\MatureAccess;
use App\Catalog\Taxonomy\LegacyTaxonomy;
use App\Classification\DeweyCounts;
use App\Discovery\Search\HybridSearch;
use App\Enums\MediaFamily;
use App\Livewire\FilteredPage;
use App\Models\Genre;
use App\Models\Media;
use App\Models\MediaType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Illuminate\Contracts\View\View;

new #[Title('Discover')] class extends FilteredPage {
    private const PER_PAGE = 24;

    private const TOP_GENRES = 12;

    /** The family of formats (MediaFamily value) the browse is narrowed to; anything else is ignored. */
    #[Url]
    public ?string $family = null;

    /**
     * A media type slug from a link (`?type=manga`): it joins the format filter at once and then clears itself, so the page has one state. An unknown one is ignored.
     */
    #[Url]
    public ?string $type = null;

    /**
     * A genre slug from a link (`?genre=action`, an old slug too): it joins the genre filter at once and then clears itself. An unknown one is ignored.
     */
    #[Url]
    public ?string $genre = null;

    public function mount(): void
    {
        $this->initFilters();
        $this->foldLinkedFilters();
    }

    public function updatedType(): void
    {
        $this->foldLinkedFilters();
        $this->resetPage();
    }

    public function updatedGenre(): void
    {
        $this->foldLinkedFilters();
        $this->resetPage();
    }

    /**
     * `?type=` and `?genre=` are links from before the filter panel (and the format tiles and top genres still point at them in spirit): they are added to
     * the panel's own filters, which is where the page reads them from.
     */
    private function foldLinkedFilters(): void
    {
        if ($this->type !== null) {
            $slug = MediaType::query()->where('slug', $this->type)->value('slug');

            $slug === null || $this->filters['mediaTypes'] = array_values(array_unique([...$this->filters['mediaTypes'], $slug]));
            $this->type = null;
        }

        if ($this->genre !== null) {
            // A slug from before the taxonomy (an old link to ?genre=action) finds the genre that replaced it.
            $slug = Genre::query()->where('slug', $this->genre)->value('slug') ?? Genre::query()->where('slug', LegacyTaxonomy::genreSlug($this->genre))->value('slug');

            $slug === null || $this->filters['genres'] = array_values(array_unique([...$this->filters['genres'], $slug]));
            $this->genre = null;
        }

        $this->settleFilters();
    }

    public function removeFilter(string $group, ?string $value = null): void
    {
        if ($group === 'family') {
            $this->family = null;
            $this->resetPage();

            return;
        }

        parent::removeFilter($group, $value);
    }

    public function clearFilters(): void
    {
        $this->family = null;
        parent::clearFilters();
    }

    /**
     * The family of formats as a chip of its own, ahead of the rest.
     *
     * @return list<array{group: string, label: string|null, chips: list<array{value: string|null, text: string, remove: string}>}>
     */
    protected function extraChipGroups(): array
    {
        if ($this->activeFamily === null) {
            return [];
        }

        $text = $this->familyLabel($this->activeFamily);

        return [['group' => 'family', 'label' => __('Family'), 'chips' => [['value' => null, 'text' => $text, 'remove' => __('Remove filter :label', ['label' => $text])]]]];
    }

    /**
     * The header is a band across the whole page, so the layout leaves `<main>` bare (see layouts/app).
     */
    public function render(): View
    {
        return $this->view()->layoutData(['flush' => true]);
    }

    #[Computed]
    public function activeFamily(): ?MediaFamily
    {
        return MediaFamily::tryFrom((string) $this->family);
    }

    /**
     * Whether anything narrows the stories: a family of formats, or any filter.
     */
    #[Computed]
    public function isFiltered(): bool
    {
        return $this->activeFamily !== null || ! $this->searchFilters()->isEmpty();
    }

    /**
     * Every media type with at least one story, the biggest first, each with its `stories_count` (the live count, merged and deleted
     * stories left out). The family filter narrows the list to its formats.
     *
     * @return SupportCollection<int, MediaType>
     */
    #[Computed]
    public function types(): SupportCollection
    {
        // Kept ten minutes as `[type id => count]` (the cache refuses to unserialize models), once for readers who may see mature titles and once for the rest.
        $viewer = auth()->user();
        $counts = Cache::remember('discover:type-counts:v2:'.MatureAccess::cacheKey($viewer), 600, fn (): array => Media::query()->unmerged()->visibleTo($viewer)->selectRaw('media.media_type_id, count(*) as total')->groupBy('media.media_type_id')->pluck('total', 'media_type_id')->map(fn (mixed $total): int => (int) $total)->all());

        return MediaType::query()->orderBy('name')->get()
            ->each(fn (MediaType $type) => $type->setAttribute('stories_count', $counts[$type->id] ?? 0))
            ->filter(fn (MediaType $type): bool => $type->stories_count > 0)
            ->sortByDesc('stories_count')
            ->values();
    }

    /**
     * The families that hold a story, for the switch above the format tiles.
     *
     * @return SupportCollection<int, MediaFamily>
     */
    #[Computed]
    public function families(): SupportCollection
    {
        return collect(MediaFamily::cases())->filter(fn (MediaFamily $family): bool => $this->types->contains(fn (MediaType $type): bool => $type->family === $family))->values();
    }

    /**
     * The format tiles on show: all of them, or those of the active family.
     *
     * @return SupportCollection<int, MediaType>
     */
    #[Computed]
    public function tiles(): SupportCollection
    {
        return $this->activeFamily === null ? $this->types : $this->types->filter(fn (MediaType $type): bool => $type->family === $this->activeFamily)->values();
    }

    /**
     * Up to three covers per media type, the most rated first, for the strip on each tile (one query, kept ten minutes as plain rows).
     *
     * @return array<int, list<string>>
     */
    #[Computed]
    public function covers(): array
    {
        $viewer = auth()->user();

        return Cache::remember('discover:covers:v2:'.MatureAccess::cacheKey($viewer), 600, fn (): array => DB::query()
            ->fromSub(
                Media::query()->unmerged()->visibleTo($viewer)->whereNotNull('media.cover_url')->selectRaw('media.media_type_id, media.cover_url, row_number() over (partition by media.media_type_id order by media.ratings_count desc, media.id) as position')->toBase(),
                'ranked',
            )
            ->where('position', '<=', 3)
            ->orderBy('media_type_id')->orderBy('position')
            ->get()
            ->groupBy('media_type_id')
            ->map(fn (SupportCollection $rows): array => $rows->pluck('cover_url')->all())
            ->all());
    }

    /**
     * Up to three covers for the header's collage: the most rated story of each of the biggest formats, so the three look different.
     *
     * @return list<string>
     */
    #[Computed]
    public function collage(): array
    {
        return $this->types->map(fn (MediaType $type): ?string => $this->covers[$type->id][0] ?? null)->filter()->take(3)->values()->all();
    }

    /**
     * The genres with the most stories, each with its live `media_count`.
     *
     * @return Collection<int, Genre>
     */
    #[Computed]
    public function genres(): Collection
    {
        $counts = array_slice($this->genreCounts, 0, self::TOP_GENRES, true);
        $genres = Genre::query()->whereIn('id', array_keys($counts))->get()->keyBy('id');

        return (new Collection(array_keys($counts)))
            ->map(fn (int $id): ?Genre => $genres->get($id)?->setAttribute('media_count', $counts[$id]))
            ->filter()
            ->values();
    }

    /**
     * How many genres have at least one story, for the intro (the chips show only the top few).
     */
    #[Computed]
    public function genreTotal(): int
    {
        return count($this->genreCounts);
    }

    /**
     * Every genre family that has a story, the biggest first, as `[genre id => live story count]`, kept ten minutes. A family counts the stories of its subgenres too.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function genreCounts(): array
    {
        $mature = MatureAccess::allows(auth()->user());

        return Cache::remember('discover:genre-counts:v3:'.($mature ? 'mature' : 'gated'), 600, fn (): array => Genre::familyCounts($mature));
    }

    /**
     * @return Collection<int, \App\Models\DeweyClass>
     */
    #[Computed]
    public function subjects(): Collection
    {
        return app(DeweyCounts::class)->rootsWithCounts(auth()->user());
    }

    /**
     * One page of the narrowed stories, most rated first, or null while nothing is narrowed. A family of formats stands for all of its formats
     * until a format is picked.
     *
     * @return LengthAwarePaginator<int, Media>|null
     */
    #[Computed]
    public function results(): ?LengthAwarePaginator
    {
        if (! $this->isFiltered) {
            return null;
        }

        $formats = $this->activeFamily !== null && $this->filters['mediaTypes'] === []
            ? ['mediaTypes' => MediaType::query()->where('family', $this->activeFamily)->pluck('slug')->all()]
            : [];

        $page = app(HybridSearch::class)->browse($this->searchFilters($formats), self::PER_PAGE, auth()->user());

        (new Collection($page->items()))->load('mediaType');

        return $page;
    }

    /**
     * The formats and genres in force, named, for the heading above the results (a few of them, then how many more).
     */
    #[Computed]
    public function resultsTitle(): string
    {
        $labels = $this->facets;
        $names = collect([
            ...array_map(fn (string $slug): string => $labels['mediaTypes'][$slug] ?? $slug, $this->filters['mediaTypes']),
            ...($this->filters['mediaTypes'] === [] && $this->activeFamily ? [$this->familyLabel($this->activeFamily)] : []),
            ...array_map(fn (string $slug): string => $labels['genres'][$slug] ?? $slug, $this->filters['genres']),
        ]);

        return match (true) {
            $names->isEmpty() => __('Filtered stories'),
            $names->count() > 3 => $names->take(3)->implode(', ').' '.__('and :count more', ['count' => $names->count() - 3]),
            default => $names->implode(', '),
        };
    }

    public function familyLabel(MediaFamily $family): string
    {
        return match ($family) {
            MediaFamily::Prose => __('Prose'),
            MediaFamily::SequentialArt => __('Sequential art'),
            MediaFamily::Illustrated => __('Illustrated'),
            MediaFamily::Interactive => __('Interactive'),
        };
    }

    /**
     * The URL of this page for a state of the filters (and the family of formats), without what is empty. Going somewhere new jumps to the results;
     * dropping a filter stays where the reader is.
     *
     * @param  array<string, mixed>  $filters
     */
    private function linkFor(array $filters, ?MediaFamily $family, bool $jump): string
    {
        $query = array_filter(['family' => $family?->value, 'filters' => array_filter($filters)]);

        return route('discover', $query).($jump ? '#results' : '');
    }

    /**
     * This page with one value of a filter turned on or, when it is on, off, keeping everything else.
     */
    public function toggleLink(string $group, string $value): string
    {
        $values = $this->filters[$group];
        $on = ! in_array($value, $values, true);

        return $this->linkFor(
            [...$this->filters, $group => $on ? [...$values, $value] : array_values(array_diff($values, [$value]))],
            $this->activeFamily,
            $on,
        );
    }

    /**
     * This page with another family of formats (null for all of them), keeping the filters.
     */
    public function familyLink(?MediaFamily $family): string
    {
        return $this->linkFor($this->filters, $family, false);
    }
}; ?>

@php
    $total = $this->types->sum('stories_count');
    $hasCatalog = $total > 0;
    $quickLinks = collect([
        ['label' => __('Continue reading'), 'route' => 'library', 'icon' => 'book-open'],
        ['label' => __('My collections'), 'route' => 'collections.index', 'icon' => 'rectangle-stack'],
    ])->filter(fn (array $link): bool => Route::has($link['route']))->values()->all();
@endphp

<div class="pb-12">
    @if ($hasCatalog)
        <x-filter-islands :panel="fn () => $this->panel" :years="$this->facets['years']" />
    @endif

    <header class="relative isolate overflow-hidden bg-surface-deep">
        <div class="flex items-center justify-between gap-12 px-gutter py-8 lg:py-12">
            <div class="min-w-0 flex-1">
                <x-page-header :title="__('Discover')" :description="__('Browse the whole catalog by format, genre and subject. Pick a shelf to start, or search for something specific.')" />

                @if ($hasCatalog)
                    <p data-test="catalog-stats" class="mt-3 max-w-prose text-meta tabular-nums text-ink-subtle">
                        {{ trans_choice(':count story|:count stories', $total) }}
                        {{ trans_choice('in :count format|in :count formats', $this->types->count()) }}
                        {{ trans_choice('and :count genre|and :count genres', $this->genreTotal) }}.
                    </p>
                @endif

                <x-search-panel :action="route('search')" :browse="false" :placeholder="__('Titles, authors, series and subjects')" class="mt-8" />
            </div>

            @if ($this->collage !== [])
                <div aria-hidden="true" data-test="collage" class="hidden shrink-0 items-end md:flex">
                    @foreach ($this->collage as $cover)
                        <x-book-3d :src="$cover" size="md" :tilt="['-22deg', '-12deg', '-4deg'][$loop->index]" class="-ms-12 first:ms-0 {{ ['mb-0', 'mb-6', 'mb-12'][$loop->index] }}" />
                    @endforeach
                </div>
            @endif
        </div>
    </header>

    <div class="px-gutter">
    @if ($quickLinks !== [])
        <x-quick-links :links="$quickLinks" class="mt-8" />
    @endif

    @if ($hasCatalog)
        <section aria-labelledby="formats-title" class="mt-12 lg:mt-16">
            <h2 id="formats-title" class="text-section text-ink">{{ __('Browse by Format') }}</h2>
            <p class="mt-1 text-meta text-ink-subtle">{{ __('Pick a format to see its stories, then narrow by genre.') }}</p>

            @if ($this->families->count() > 1)
                <nav aria-label="{{ __('Format families') }}" class="mt-4">
                    <ul class="flex flex-wrap gap-2">
                        <li>
                            <x-chip :href="$this->familyLink(null)" wire:navigate :active="$this->activeFamily === null">{{ __('All formats') }}</x-chip>
                        </li>
                        @foreach ($this->families as $family)
                            <li wire:key="family-{{ $family->value }}">
                                <x-chip :href="$this->familyLink($family)" wire:navigate :active="$this->activeFamily === $family">{{ $this->familyLabel($family) }}</x-chip>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            <ul x-data x-motion-reveal data-stagger="30" class="mt-6 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-[repeat(auto-fill,minmax(15rem,1fr))]">
                @foreach ($this->tiles as $tile)
                    @php $isActive = in_array($tile->slug, $this->filters['mediaTypes'], true); @endphp
                    <li wire:key="format-{{ $tile->id }}">
                        <a
                            href="{{ $this->toggleLink('mediaTypes', $tile->slug) }}"
                            wire:navigate
                            data-format-tile
                            @if ($isActive) aria-current="true" @endif
                            class="group focus-ring flex h-full min-h-44 flex-col justify-between gap-6 rounded-card p-4 sm:p-5 {{ $isActive ? 'bg-surface-card ring-1 ring-ink' : 'bg-surface-deep hover:bg-surface-card' }}"
                        >
                            <span class="block">
                                <span class="block text-section tracking-tight text-ink">{{ $tile->name }}</span>
                                <span class="mt-1 block text-meta tabular-nums text-ink-subtle">{{ trans_choice(':count story|:count stories', $tile->stories_count) }}</span>
                            </span>

                            <span aria-hidden="true" class="flex -space-x-3">
                                @foreach (array_pad(array_slice($this->covers[$tile->id] ?? [], 0, 3), min(3, $tile->stories_count), null) as $cover)
                                    <span class="relative block aspect-[2/3] w-12 overflow-hidden rounded-control bg-surface-card ring-2 {{ $isActive ? 'ring-surface-card' : 'ring-surface-deep group-hover:ring-surface-card' }} motion-safe:transition-transform motion-safe:duration-(--motion-fast) motion-safe:ease-out-bookflix motion-safe:group-hover:-translate-y-0.5 sm:w-16" style="z-index: {{ 3 - $loop->index }}">
                                        @if ($cover)
                                            <img src="{{ $cover }}" alt="" width="64" height="96" loading="lazy" decoding="async" class="size-full object-cover">
                                        @else
                                            <x-cover-fallback :title="$tile->name" compact />
                                        @endif
                                    </span>
                                @endforeach
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>

        @if ($this->genres->isNotEmpty())
            <section aria-labelledby="genres-title" class="mt-12 lg:mt-16">
                <h2 id="genres-title" class="text-section text-ink">{{ __('Top Genres') }}</h2>
                <p class="mt-1 text-meta text-ink-subtle">{{ __('The genres with the most stories. Select one to filter the results, or use the filters below for subgenres, themes and more.') }}</p>

                <ul class="mt-4 flex flex-wrap gap-2">
                    @foreach ($this->genres as $genre)
                        @php $isActive = in_array($genre->slug, $this->filters['genres'], true); @endphp
                        <li wire:key="genre-{{ $genre->id }}">
                            <x-chip :href="$this->toggleLink('genres', $genre->slug)" wire:navigate data-genre-chip :active="$isActive">
                                <span>{{ $genre->name }}</span>
                                <span class="tabular-nums {{ $isActive ? 'text-canvas' : 'text-ink-subtle' }}">{{ number_format($genre->media_count) }}</span>
                            </x-chip>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section id="results" aria-labelledby="results-title" class="mt-12 scroll-mt-20 lg:mt-16">
            <h2 id="results-title" class="text-section text-ink">{{ $this->isFiltered ? $this->resultsTitle : __('Find Stories') }}</h2>
            <p class="mt-1 text-meta text-ink-subtle">{{ __('Combine genres, subgenres, themes, tropes and more. Each group narrows the results on its own.') }}</p>

            <div x-data="filterPanel(@js($this->panelConfig))" x-on:filter-sheet-close="discard()" class="mt-6 lg:grid lg:grid-cols-[20rem_minmax(0,1fr)] lg:gap-12">
                <aside aria-labelledby="discover-filters-title" class="hidden self-start lg:sticky lg:top-20 lg:block">
                    <div class="flex min-h-11 items-center justify-between gap-4">
                        <h3 id="discover-filters-title" class="text-lead text-ink">{{ __('Filters') }}</h3>

                        <button type="button" x-show="activeCount > 0 || dirty" style="display: none" @click="clear()" class="focus-ring -mx-2 inline-flex min-h-11 items-center gap-1 rounded-control px-2 text-meta text-link hover:underline"><flux:icon name="x-mark" variant="micro" class="size-4" />{{ __('Clear all') }}</button>
                    </div>

                    <div wire:ignore x-html="desktop ? panelHtml : ''" class="mt-2 max-h-[calc(100dvh-9rem)] overflow-y-auto overscroll-contain pe-2"></div>
                </aside>

                <div class="min-w-0">
                    <div class="flex min-h-11 flex-wrap items-center justify-between gap-3">
                        <p role="status" data-test="result-count" class="text-meta tabular-nums text-ink-subtle">
                            @if ($this->results)
                                {{ trans_choice('{0} No stories|{1} 1 story|[2,*] :count stories', $this->results->total()) }}
                            @endif
                        </p>

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
                    </div>

                    @include('pages.search.chips')

                    <div wire:loading.delay.shortest.class="opacity-60" class="motion-safe:transition-opacity">
                        @if ($this->results === null)
                            <p class="mt-6 max-w-prose text-body text-ink-subtle">{{ __('Pick a format or a genre above, or use the filters, to list stories here.') }}</p>
                        @elseif ($this->results->isNotEmpty())
                            <ul class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
                                @foreach ($this->results as $media)
                                    <li wire:key="result-{{ $media->id }}">
                                        <x-cover-tile :media="$media" :badge="count($this->filters['mediaTypes']) !== 1" fluid />
                                    </li>
                                @endforeach
                            </ul>

                            <x-pager :paginator="$this->results" class="mt-8" />
                        @else
                            <x-empty-state :title="__('No stories match')" heading="h3" class="mt-6">
                                <p class="mt-2 text-body text-ink-subtle">{{ __('No story has all of those. Drop a filter to see more.') }}</p>

                                <div class="mt-6 flex flex-wrap gap-3">
                                    <x-cta variant="secondary" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</x-cta>
                                </div>
                            </x-empty-state>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    @else
        <x-empty-catalog class="mt-12 lg:mt-16" />
    @endif

    <section aria-labelledby="subjects-title" class="mt-12 lg:mt-16">
        <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-1">
            <div>
                <h2 id="subjects-title" class="text-section text-ink">{{ __('Explore by Subject') }}</h2>
                <p class="mt-1 text-meta text-ink-subtle">{{ __('Dewey classes, from the 000s to the 900s.') }}</p>
            </div>
            <a href="{{ route('explore.index') }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center gap-1 rounded-control px-2 -mx-2 text-meta text-link hover:underline">{{ __('Explore the Library') }}<flux:icon name="arrow-right" variant="micro" class="size-4" /></a>
        </div>

        @if ($this->subjects->isNotEmpty())
            <ul x-data x-motion-reveal data-stagger="30" class="mt-4 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-[repeat(auto-fill,minmax(13rem,16rem))]">
                @foreach ($this->subjects as $subject)
                    <li wire:key="subject-{{ $subject->id }}">
                        <x-subject-tile :dewey-class="$subject" fluid />
                    </li>
                @endforeach
            </ul>
        @else
            <p class="mt-2 max-w-prose text-body text-ink-subtle">{{ __('Stories are shelved by subject in a Dewey-inspired library. The shelves fill as stories are classified.') }}</p>
        @endif
    </section>
    </div>
</div>
