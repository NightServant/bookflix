<?php

use App\Access\MatureAccess;
use App\Catalog\Taxonomy\LegacyTaxonomy;
use App\Classification\DeweyCounts;
use App\Discovery\EditorialCollections;
use App\Discovery\Shelf;
use App\Enums\MediaFamily;
use App\Livewire\FilteredPage;
use App\Models\Genre;
use App\Models\Media;
use App\Models\MediaType;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Discover')] class extends Component {
    /** The family of formats (MediaFamily value) the format tiles are narrowed to; anything else is ignored. */
    #[Url]
    public ?string $family = null;

    /**
     * Discover no longer lists stories: a link that carries a filter (`?type=manga`, `?genre=action`, `?filters[...]`) goes to the search page with it,
     * so old bookmarks keep working. A family of formats alone stays here (it switches the format tiles).
     */
    public function mount(): void
    {
        $filters = request()->query('filters');
        $filters = is_array($filters) ? $filters : [];

        $type = request()->query('type');
        $slug = is_string($type) ? MediaType::query()->where('slug', $type)->value('slug') : null;
        $slug === null || $filters['mediaTypes'] = [...Arr::wrap($filters['mediaTypes'] ?? []), $slug];

        $genre = request()->query('genre');
        $slug = is_string($genre) ? (Genre::query()->where('slug', $genre)->value('slug') ?? Genre::query()->where('slug', LegacyTaxonomy::genreSlug($genre))->value('slug')) : null;
        $slug === null || $filters['genres'] = [...Arr::wrap($filters['genres'] ?? []), $slug];

        // Only the known groups count, and a group with nothing in it is not a filter.
        $filters = Arr::only($filters, array_keys(FilteredPage::NO_FILTERS));
        $prune = function (array $values) use (&$prune): array {
            return array_filter(array_map(fn (mixed $value): mixed => is_array($value) ? $prune($value) : $value, $values), fn (mixed $value): bool => $value !== [] && $value !== '' && $value !== null);
        };
        $filters = $prune($filters);

        if ($filters === []) {
            return;
        }

        // A family stands for all of its formats until a format is picked.
        if ($this->activeFamily !== null && empty($filters['mediaTypes'])) {
            $filters['mediaTypes'] = MediaType::query()->where('family', $this->activeFamily)->pluck('slug')->all();
        }

        $this->redirect(route('search', ['filters' => $filters]), navigate: true);
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
        // A book that is more than one type is on each of its tiles, and counted once in the catalogue (`main_count`).
        $also = Cache::remember('discover:additional-type-counts:v1:'.MatureAccess::cacheKey($viewer), 600, fn (): array => Media::query()->unmerged()->visibleTo($viewer)->join('media_additional_types as additional', 'additional.media_id', '=', 'media.id')->selectRaw('additional.media_type_id, count(*) as total')->groupBy('additional.media_type_id')->pluck('total', 'media_type_id')->map(fn (mixed $total): int => (int) $total)->all());

        return MediaType::query()->orderBy('name')->get()
            ->each(fn (MediaType $type) => $type->setAttribute('main_count', $counts[$type->id] ?? 0)->setAttribute('stories_count', ($counts[$type->id] ?? 0) + ($also[$type->id] ?? 0)))
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

        return Cache::remember('discover:covers:v3:'.MatureAccess::cacheKey($viewer), 600, fn (): array => DB::query()
            ->fromSub(
                Media::query()->unmerged()->visibleTo($viewer)->whereNotNull('media.cover_url')->selectRaw('media.id, media.media_type_id, media.cover_url, row_number() over (partition by media.media_type_id order by media.ratings_count desc, media.id) as position')->toBase(),
                'ranked',
            )
            ->where('position', '<=', 3)
            ->orderBy('media_type_id')->orderBy('position')
            ->get()
            ->groupBy('media_type_id')
            ->map(fn (SupportCollection $rows): array => $rows->map(fn (stdClass $row): string => (string) Media::displayCover((int) $row->id, $row->cover_url))->all())
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
     * The staff picks as themed shelves: the editorial collections with something in them that the reader may see.
     *
     * @return SupportCollection<int, Shelf>
     */
    #[Computed]
    public function editorial(): SupportCollection
    {
        return app(EditorialCollections::class)->shelves(auth()->user());
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
     * This page with another family of formats (null for all of them).
     */
    public function familyLink(?MediaFamily $family): string
    {
        return route('discover', array_filter(['family' => $family?->value]));
    }
}; ?>

@php
    $total = $this->types->sum('main_count');
    $hasCatalog = $total > 0;
    $quickLinks = collect([
        ['label' => __('My library'), 'route' => 'library.index', 'icon' => 'book-open'],
        ['label' => __('My collections'), 'route' => 'collections.index', 'icon' => 'rectangle-stack'],
        ['label' => __('My history'), 'route' => 'history.index', 'icon' => 'clock'],
    ])->filter(fn (array $link): bool => Route::has($link['route']))->values()->all();
@endphp

<div class="pb-12">
    {{-- The page's featured band: the Trending Now gradient, so its quiet text is ink at 80%. --}}
    <header class="relative isolate overflow-hidden bg-linear-to-r from-featured-from via-featured-via to-featured-to">
        <div class="flex items-center justify-between gap-12 px-gutter py-8 lg:py-12">
            <div class="min-w-0 flex-1">
                <x-page-header featured :title="__('Discover')" :description="__('Browse the whole catalog by format, genre and subject. Pick a shelf to start, or search for something specific.')" />

                @if ($hasCatalog)
                    <p data-test="catalog-stats" class="mt-3 max-w-prose text-meta tabular-nums text-ink/80">
                        {{ trans_choice(':count story|:count stories', $total) }}
                        {{ trans_choice('in :count format|in :count formats', $this->types->count()) }}
                        {{ trans_choice('and :count genre|and :count genres', $this->genreTotal) }}.
                    </p>
                @endif

                <x-search-panel :action="route('search')" :browse="false" :placeholder="__('Titles, authors, series and subjects')" class="mt-8 [&_input[data-flux-control]]:bg-surface-input" />
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

    <x-editorial-shelves :shelves="$this->editorial" class="mt-12 lg:mt-16" />

    @if ($hasCatalog)
        <section aria-labelledby="formats-title" class="mt-12 lg:mt-16">
            <h2 id="formats-title" class="text-section text-ink">{{ __('Browse by Format') }}</h2>
            <p class="mt-1 text-meta text-ink-subtle">{{ __('Pick a format to see its stories on the search page, then narrow by genre.') }}</p>

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

            <ul x-data x-motion-reveal data-stagger="30" class="mt-6 grid grid-cols-[repeat(2,minmax(0,1fr))] gap-3 sm:gap-4 md:grid-cols-[repeat(auto-fill,minmax(15rem,1fr))]">
                @foreach ($this->tiles as $tile)
                    <li wire:key="format-{{ $tile->id }}">
                        <a
                            href="{{ route('search', ['filters' => ['mediaTypes' => [$tile->slug]]]) }}"
                            wire:navigate
                            data-format-tile
                            class="group focus-ring flex h-full min-h-44 flex-col justify-between gap-6 rounded-card p-4 sm:p-5 bg-surface-deep hover:bg-surface-card"
                        >
                            <span class="block">
                                <span class="block text-section tracking-tight text-ink wrap-anywhere">{{ $tile->name }}</span>
                                <span class="mt-1 block text-meta tabular-nums text-ink-subtle">{{ trans_choice(':count story|:count stories', $tile->stories_count) }}</span>
                            </span>

                            <span aria-hidden="true" class="flex -space-x-3">
                                @foreach (array_pad(array_slice($this->covers[$tile->id] ?? [], 0, 3), min(3, $tile->stories_count), null) as $cover)
                                    <span class="relative block aspect-[2/3] w-12 overflow-hidden rounded-control bg-surface-card ring-2 ring-surface-deep group-hover:ring-surface-card motion-safe:transition-transform motion-safe:duration-(--motion-fast) motion-safe:ease-out-bookflix motion-safe:group-hover:-translate-y-0.5 sm:w-16" style="z-index: {{ 3 - $loop->index }}">
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
            <ul x-data x-motion-reveal data-stagger="30" class="mt-4 grid grid-cols-[repeat(2,minmax(0,1fr))] gap-3 sm:gap-4 md:grid-cols-[repeat(auto-fill,minmax(13rem,16rem))]">
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
