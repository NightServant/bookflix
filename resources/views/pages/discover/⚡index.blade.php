<?php

use App\Classification\DeweyCounts;
use App\Discovery\Search\HybridSearch;
use App\Discovery\Search\SearchFilters;
use App\Enums\MediaFamily;
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
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Discover')] class extends Component {
    use WithPagination;

    private const PER_PAGE = 24;

    private const TOP_GENRES = 12;

    /** The family of formats (MediaFamily value) the browse is narrowed to; anything else is ignored. */
    #[Url]
    public ?string $family = null;

    /** A media type slug; an unknown one is ignored. */
    #[Url]
    public ?string $type = null;

    /** A genre slug; an unknown one is ignored. */
    #[Url]
    public ?string $genre = null;

    #[Computed]
    public function activeFamily(): ?MediaFamily
    {
        return MediaFamily::tryFrom((string) $this->family);
    }

    #[Computed]
    public function activeType(): ?MediaType
    {
        return $this->type === null ? null : MediaType::query()->where('slug', $this->type)->first();
    }

    #[Computed]
    public function activeGenre(): ?Genre
    {
        return $this->genre === null ? null : Genre::query()->where('slug', $this->genre)->first();
    }

    #[Computed]
    public function isFiltered(): bool
    {
        return $this->activeFamily !== null || $this->activeType !== null || $this->activeGenre !== null;
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
        $counts = Media::query()->unmerged()->selectRaw('media.media_type_id, count(*) as total')->groupBy('media.media_type_id')->pluck('total', 'media_type_id');

        return MediaType::query()->orderBy('name')->get()
            ->each(fn (MediaType $type) => $type->setAttribute('stories_count', (int) $counts->get($type->id, 0)))
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
        return Cache::remember('discover:covers:v1', 600, fn (): array => DB::query()
            ->fromSub(
                Media::query()->unmerged()->whereNotNull('media.cover_url')->selectRaw('media.media_type_id, media.cover_url, row_number() over (partition by media.media_type_id order by media.ratings_count desc, media.id) as position')->toBase(),
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
     * The genres with the most stories, each with its live `media_count`.
     *
     * @return Collection<int, Genre>
     */
    #[Computed]
    public function genres(): Collection
    {
        return Genre::query()
            ->withCount(['media' => fn ($media) => $media->unmerged()])
            ->orderByDesc('media_count')
            ->orderBy('name')
            ->limit(self::TOP_GENRES)
            ->get()
            ->filter(fn (Genre $genre): bool => $genre->media_count > 0)
            ->values();
    }

    /**
     * @return Collection<int, \App\Models\DeweyClass>
     */
    #[Computed]
    public function subjects(): Collection
    {
        return app(DeweyCounts::class)->rootsWithCounts();
    }

    /**
     * One page of the narrowed stories, most rated first, or null while nothing is narrowed.
     *
     * @return LengthAwarePaginator<int, Media>|null
     */
    #[Computed]
    public function results(): ?LengthAwarePaginator
    {
        if (! $this->isFiltered) {
            return null;
        }

        $slugs = match (true) {
            $this->activeType !== null => [$this->activeType->slug],
            $this->activeFamily !== null => MediaType::query()->where('family', $this->activeFamily)->pluck('slug')->all(),
            default => [],
        };

        $page = app(HybridSearch::class)->browse(new SearchFilters(mediaTypes: $slugs, genres: $this->activeGenre ? [$this->activeGenre->slug] : []), self::PER_PAGE);

        (new Collection($page->items()))->load('mediaType');

        return $page;
    }

    /**
     * The filters in force, named, for the heading above the results.
     */
    #[Computed]
    public function resultsTitle(): string
    {
        return collect([
            $this->activeType?->name ?? ($this->activeFamily ? $this->familyLabel($this->activeFamily) : null),
            $this->activeGenre?->name,
        ])->filter()->implode(', ');
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
     * The URL of this page with one filter swapped (a null value drops it), keeping the others. A new filter chosen from the tiles
     * jumps to the results; dropping one stays where the reader is.
     *
     * @param  array<string, string|null>  $change
     */
    public function linkTo(array $change): string
    {
        $query = array_filter([
            'family' => $this->activeFamily?->value,
            'type' => $this->activeType?->slug,
            'genre' => $this->activeGenre?->slug,
            ...$change,
        ]);

        return route('discover', $query).(array_filter($change) !== [] ? '#results' : '');
    }
}; ?>

@php
    $total = $this->types->sum('stories_count');
    $hasCatalog = $total > 0;
    $quickLinks = collect([
        ['label' => __('Continue reading'), 'route' => 'library', 'icon' => 'book-open'],
        ['label' => __('My collections'), 'route' => 'collections.index', 'icon' => 'rectangle-stack'],
        ['label' => __('Browse by subject'), 'route' => 'explore.index', 'icon' => 'squares-2x2'],
        ['label' => __('Filter the catalog'), 'route' => 'search', 'icon' => 'adjustments-horizontal'],
    ])->filter(fn (array $link): bool => Route::has($link['route']))->values()->all();
    $chip = 'focus-ring inline-flex min-h-11 items-center gap-2 rounded-full px-4 text-meta';
@endphp

<section class="pt-2 pb-12 lg:pt-6">
    <header>
        <h1 class="text-hero tracking-tight text-ink">{{ __('Discover') }}</h1>

        @if ($hasCatalog)
            <p class="mt-2 max-w-prose text-body tabular-nums text-ink-subtle">
                {{ trans_choice(':count story|:count stories', $total) }}
                {{ trans_choice('in :count format|in :count formats', $this->types->count()) }}
                {{ trans_choice('and :count genre|and :count genres', $this->genres->count()) }}.
            </p>
        @endif
    </header>

    <x-search-panel :action="route('search')" :browse="false" :placeholder="__('Titles, authors, series and subjects')" class="mt-8 max-w-3xl" />

    @if ($quickLinks !== [])
        <x-quick-links :links="$quickLinks" class="mt-8" />
    @endif

    @if ($hasCatalog)
        <section aria-labelledby="formats-title" class="mt-12 lg:mt-16">
            <h2 id="formats-title" class="text-section text-ink">{{ __('Browse by format') }}</h2>

            @if ($this->families->count() > 1)
                <nav aria-label="{{ __('Format families') }}" class="mt-4">
                    <ul class="flex flex-wrap gap-2">
                        <li>
                            <a href="{{ route('discover') }}" wire:navigate @if ($this->activeFamily === null) aria-current="true" @endif class="{{ $chip }} {{ $this->activeFamily === null ? 'bg-ink text-canvas' : 'bg-surface-input text-ink hover:bg-hairline' }}">{{ __('All formats') }}</a>
                        </li>
                        @foreach ($this->families as $family)
                            <li wire:key="family-{{ $family->value }}">
                                <a href="{{ route('discover', ['family' => $family->value]) }}" wire:navigate @if ($this->activeFamily === $family) aria-current="true" @endif class="{{ $chip }} {{ $this->activeFamily === $family ? 'bg-ink text-canvas' : 'bg-surface-input text-ink hover:bg-hairline' }}">{{ $this->familyLabel($family) }}</a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            <ul x-data x-motion-reveal data-stagger="30" class="mt-6 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-[repeat(auto-fit,minmax(13.5rem,1fr))]">
                @foreach ($this->tiles as $tile)
                    @php $isActive = $this->activeType?->is($tile) ?? false; @endphp
                    <li wire:key="format-{{ $tile->id }}">
                        <a
                            href="{{ $this->linkTo(['type' => $isActive ? null : $tile->slug]) }}"
                            wire:navigate
                            data-format-tile
                            @if ($isActive) aria-current="true" @endif
                            class="group focus-ring flex h-full min-h-44 flex-col justify-between gap-6 rounded-card p-4 sm:p-5 {{ $isActive ? 'bg-surface-card ring-1 ring-ink' : 'bg-surface-deep hover:bg-surface-card' }}"
                        >
                            <span class="block">
                                <span class="block text-section tracking-tight text-ink">{{ $tile->name }}</span>
                                <span class="mt-1 block text-meta tabular-nums text-ink-subtle">{{ trans_choice(':count story|:count stories', $tile->stories_count) }}</span>
                            </span>

                            @if (($this->covers[$tile->id] ?? []) !== [])
                                <span aria-hidden="true" class="flex gap-1">
                                    @foreach ($this->covers[$tile->id] as $cover)
                                        <img src="{{ $cover }}" alt="" width="48" height="72" loading="lazy" decoding="async" class="aspect-[2/3] w-10 rounded-control sm:w-12 bg-surface-card object-cover motion-safe:transition-transform motion-safe:duration-(--motion-fast) motion-safe:ease-out-bookflix motion-safe:group-hover:scale-105">
                                    @endforeach
                                </span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>

        @if ($this->genres->isNotEmpty())
            <section aria-labelledby="genres-title" class="mt-12 lg:mt-16">
                <h2 id="genres-title" class="text-section text-ink">{{ __('Top genres') }}</h2>

                <ul class="mt-4 flex flex-wrap gap-2">
                    @foreach ($this->genres as $genre)
                        @php $isActive = $this->activeGenre?->is($genre) ?? false; @endphp
                        <li wire:key="genre-{{ $genre->id }}">
                            <a
                                href="{{ $this->linkTo(['genre' => $isActive ? null : $genre->slug]) }}"
                                wire:navigate
                                data-genre-chip
                                @if ($isActive) aria-current="true" @endif
                                class="{{ $chip }} {{ $isActive ? 'bg-ink text-canvas' : 'bg-surface-input text-ink hover:bg-hairline' }}"
                            >
                                <span>{{ $genre->name }}</span>
                                <span class="tabular-nums {{ $isActive ? 'text-canvas' : 'text-ink-subtle' }}">{{ number_format($genre->media_count) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($this->results)
            <section id="results" aria-labelledby="results-title" class="mt-12 scroll-mt-20 lg:mt-16">
                <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-1">
                    <h2 id="results-title" class="text-section text-ink">{{ $this->resultsTitle }}</h2>
                    <a href="{{ route('discover') }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center rounded-control px-2 text-meta text-link hover:underline">{{ __('Clear filters') }}</a>
                </div>

                <p role="status" class="text-meta tabular-nums text-ink-subtle">{{ trans_choice('{0} No stories|{1} 1 story|[2,*] :count stories', $this->results->total()) }}</p>

                @if ($this->results->isNotEmpty())
                    <ul class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                        @foreach ($this->results as $media)
                            <li wire:key="result-{{ $media->id }}">
                                <x-cover-tile :media="$media" :badge="$this->activeType === null" fluid />
                            </li>
                        @endforeach
                    </ul>

                    <flux:pagination :paginator="$this->results" class="mt-8" />
                @else
                    <div class="mt-6 max-w-prose">
                        <p class="text-body text-ink-subtle">{{ __('No story has both of those. Drop a filter to see more.') }}</p>
                        <x-cta variant="secondary" :href="route('discover')" wire:navigate class="mt-6">{{ __('Clear filters') }}</x-cta>
                    </div>
                @endif
            </section>
        @endif
    @else
        <x-empty-catalog class="mt-12 lg:mt-16" />
    @endif

    <section aria-labelledby="subjects-title" class="mt-12 lg:mt-16">
        <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-1">
            <h2 id="subjects-title" class="text-section text-ink">{{ __('Explore by subject') }}</h2>
            <a href="{{ route('explore.index') }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center rounded-control px-2 text-meta text-link hover:underline">{{ __('Explore the Library') }}</a>
        </div>

        @if ($this->subjects->isNotEmpty())
            <ul x-data x-motion-reveal data-stagger="30" class="mt-4 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-[repeat(auto-fit,minmax(13.5rem,1fr))]">
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
</section>
