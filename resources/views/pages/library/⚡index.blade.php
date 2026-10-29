<?php

use App\Classification\ClassShelf;
use App\Classification\ClassShelves;
use App\Enums\ReadingStatus;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\MediaType;
use App\Models\ReadingProgress;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Library')] class extends Component {
    use WithPagination;

    private const PER_PAGE = 24;

    /** The shelves view is one long page of rows, so it takes more stories at a time. */
    private const SHELVES_PER_PAGE = 200;

    /** The sorts on offer. Rating comes with ratings (5.5), which this page waits for. */
    private const SORTS = ['added', 'title', 'last_read'];

    private const LAYOUTS = ['grid', 'list', 'shelves'];

    /** A ReadingStatus value, or null for every status. */
    #[Url]
    public ?string $status = null;

    /** A media type slug of the library, or null for every format. */
    #[Url]
    public ?string $type = null;

    #[Url]
    public bool $favorites = false;

    #[Url]
    public string $sort = 'added';

    #[Url]
    public string $view = 'grid';

    /**
     * Whatever the URL carries that the page does not offer is dropped, so a stale or crafted link opens the whole library.
     */
    public function mount(): void
    {
        $this->settle();
    }

    public function updatedStatus(): void
    {
        $this->changed();
    }

    public function updatedType(): void
    {
        $this->changed();
    }

    public function updatedFavorites(): void
    {
        $this->changed();
    }

    public function updatedSort(): void
    {
        $this->changed();
    }

    public function updatedView(): void
    {
        $this->changed();
    }

    /**
     * Take one filter off, from its chip.
     */
    public function removeFilter(string $filter): void
    {
        match ($filter) {
            'status' => $this->status = null,
            'type' => $this->type = null,
            'favorites' => $this->favorites = false,
            default => null,
        };

        $this->changed();
    }

    public function clearFilters(): void
    {
        $this->status = null;
        $this->type = null;
        $this->favorites = false;

        $this->changed();
    }

    /**
     * A new set of stories starts on the first page.
     */
    private function changed(): void
    {
        $this->settle();
        $this->resetPage();
    }

    private function settle(): void
    {
        $this->status = ReadingStatus::tryFrom((string) $this->status)?->value;
        $this->type = in_array($this->type, $this->typeOptions->pluck('slug')->all(), true) ? $this->type : null;
        $this->sort = in_array($this->sort, self::SORTS, true) ? $this->sort : 'added';
        $this->view = in_array($this->view, self::LAYOUTS, true) ? $this->view : 'grid';
    }

    /**
     * The shelves view brings its own full-width bands, so the layout leaves `<main>` bare (see layouts/app).
     */
    public function render(): View
    {
        return $this->view()->layoutData(['flush' => true]);
    }

    /**
     * The formats in the reader's library: the list the format control offers, and the only values `type` may take.
     *
     * @return Collection<int, MediaType>
     */
    #[Computed]
    public function typeOptions(): Collection
    {
        $user = auth()->user();

        return MediaType::query()
            ->whereIn('id', Media::query()->unmerged()->visibleTo($user)->whereIn('id', $user->library()->select('media_id'))->select('media_type_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
    }

    /**
     * The library the reader may see (a story behind the mature gate stays stored, but is not listed or counted), narrowed by the format and
     * favorite filters, and by the status unless `$withStatus` is off (the status tabs count every status).
     *
     * @return Builder<LibraryEntry>
     */
    private function narrowed(bool $withStatus = true): Builder
    {
        $user = auth()->user();
        $type = $this->typeOptions->firstWhere('slug', $this->type);

        return LibraryEntry::query()
            ->where('user_library.user_id', $user->id)
            ->visibleTo($user)
            ->when($type, fn (Builder $entries) => $entries->whereHas('media', fn (Builder $media) => $media->where('media.media_type_id', $type->id)))
            ->when($this->favorites, fn (Builder $entries) => $entries->whereNotNull('user_library.favorited_at'))
            ->when($withStatus && $this->status !== null, fn (Builder $entries) => $entries->where('user_library.status', $this->status));
    }

    /**
     * One page of entries with what the layout renders already loaded (lazy loading is off).
     *
     * @return LengthAwarePaginator<int, LibraryEntry>
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        $fetch = function (): LengthAwarePaginator {
            $query = $this->narrowed();

            $byTitle = Media::query()->selectRaw('lower(media.title)')->whereColumn('media.id', 'user_library.media_id');

            match ($this->sort) {
                'title' => $query->orderBy($byTitle)->orderBy('user_library.id'),
                // The story read most recently first; those never read (no progress logged) last, in title order.
                'last_read' => $query
                    ->leftJoin('reading_progress', fn (JoinClause $join) => $join->on('reading_progress.user_id', '=', 'user_library.user_id')->on('reading_progress.media_id', '=', 'user_library.media_id'))
                    ->select('user_library.*')
                    ->orderByRaw('reading_progress.last_read_at desc nulls last')
                    ->orderBy($byTitle)->orderBy('user_library.id'),
                default => $query->latest('user_library.created_at')->latest('user_library.id'),
            };

            return $query
                ->with(['media' => fn ($media) => $media->with($this->view === 'list' ? ['mediaType', 'publicPrimaryDewey.deweyClass', 'creators', 'genres'] : ['mediaType'])])
                ->paginate($this->view === 'shelves' ? self::SHELVES_PER_PAGE : self::PER_PAGE);
        };

        $page = $fetch();

        if ($page->isEmpty() && $page->currentPage() > 1 && $page->total() > 0) {
            $this->setPage($page->lastPage());
            $page = $fetch();
        }

        return $page;
    }

    /**
     * Where the reader is in each story of the page, as `[media id => percent]` (one query; the shelves view shows no progress).
     *
     * @return Collection<int, float>
     */
    #[Computed]
    public function progress(): Collection
    {
        if ($this->view === 'shelves') {
            return collect();
        }

        return ReadingProgress::query()
            ->where('user_id', auth()->id())
            ->whereIn('media_id', $this->entries->pluck('media_id')->all())
            ->pluck('percent', 'media_id')
            ->map(fn (string $percent): float => (float) $percent);
    }

    /**
     * How many stories stand at each status under the format and favorite filters, every status present (a status with none counts 0).
     *
     * @return Collection<string, int>
     */
    #[Computed]
    public function statusCounts(): Collection
    {
        $counts = $this->narrowed(withStatus: false)->toBase()->selectRaw('user_library.status, count(*) as total')->groupBy('user_library.status')->pluck('total', 'status');

        return collect(ReadingStatus::cases())->mapWithKeys(fn (ReadingStatus $status): array => [$status->value => (int) ($counts[$status->value] ?? 0)]);
    }

    /**
     * Whether nothing of the reader's is listed at all, whatever the filters: only asked when a page came back empty.
     */
    #[Computed]
    public function libraryIsEmpty(): bool
    {
        $user = auth()->user();

        return ! LibraryEntry::query()->where('user_library.user_id', $user->id)->visibleTo($user)->exists();
    }

    /**
     * The page's stories on class shelves, in the order the sort gave them.
     *
     * @return Collection<int, ClassShelf>
     */
    #[Computed]
    public function shelves(): Collection
    {
        return app(ClassShelves::class)->forMedia($this->entries->getCollection()->pluck('media'));
    }

    /**
     * The page's stories that no shelf holds (no public primary class yet), so the shelves view never loses one.
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function unshelved(): Collection
    {
        $shelved = $this->shelves->flatMap(fn (ClassShelf $shelf) => $shelf->media->pluck('id'))->all();

        return $this->entries->getCollection()->pluck('media')->reject(fn (Media $media): bool => in_array($media->id, $shelved, true))->values();
    }

    /**
     * The filters in force, one removable chip each.
     *
     * @return list<array{key: string, text: string, remove: string}>
     */
    #[Computed]
    public function chips(): array
    {
        $chips = [];

        if ($this->status !== null) {
            $chips[] = ['key' => 'status', 'text' => ReadingStatus::from($this->status)->label()];
        }

        if ($this->type !== null) {
            $chips[] = ['key' => 'type', 'text' => $this->typeOptions->firstWhere('slug', $this->type)->name];
        }

        if ($this->favorites) {
            $chips[] = ['key' => 'favorites', 'text' => __('Favorites')];
        }

        return array_map(fn (array $chip): array => $chip + ['remove' => __('Remove filter: :filter', ['filter' => $chip['text']])], $chips);
    }
}; ?>

@php
    $entries = $this->entries;
    $tools = $entries->isNotEmpty() || ! $this->libraryIsEmpty;
    $counts = $this->statusCounts;
    // A bar on a tile only for a story under way: not one that is finished, and not one at 0.
    $underWay = fn ($entry): ?float => ($percent = $this->progress[$entry->media_id] ?? null) !== null && $percent > 0 && $percent < 100 && $entry->status !== ReadingStatus::Completed ? $percent : null;
    // Every story of one format makes the format badge noise: it is shown only when the page mixes formats.
    $mixed = $entries->getCollection()->pluck('media.media_type_id')->unique()->count() > 1;
    $pill = 'focus-ring relative inline-flex h-9 items-center gap-2 rounded-full px-3 text-meta after:absolute after:inset-x-0 after:-inset-y-1 after:content-[\'\']';
    $control = 'focus-ring h-11 rounded-control border border-border-input bg-surface-input px-3 text-body text-ink';
@endphp

<div class="pb-12">
    <header class="bg-surface-deep px-gutter py-8 lg:py-12">
        <x-page-header :title="__('Library')" :description="__('Everything you have saved, with where you are in each. Filter by status or format, switch the layout, and open a story to update it.')">
            <x-slot:actions>
                <x-cta variant="secondary" icon="compass" :href="route('discover')" wire:navigate>{{ __('Browse Discover') }}</x-cta>
            </x-slot:actions>
        </x-page-header>

        <x-library-tabs current="library.index" class="mt-6" />
    </header>

    <div class="px-gutter">
        @if ($tools)
            <nav aria-label="{{ __('Status') }}" class="mt-8">
                <ul class="flex flex-wrap gap-2">
                    <li wire:key="status-all">
                        <button type="button" wire:click="$set('status', null)" aria-pressed="{{ $status === null ? 'true' : 'false' }}" @class([$pill, 'bg-ink text-canvas' => $status === null, 'bg-surface-input text-ink hover:bg-hairline' => $status !== null])>
                            {{ __('All') }} <span class="tabular-nums {{ $status === null ? '' : 'text-ink-subtle' }}">{{ $counts->sum() }}</span>
                        </button>
                    </li>
                    @foreach (ReadingStatus::cases() as $case)
                        <li wire:key="status-{{ $case->value }}">
                            <button type="button" wire:click="$set('status', '{{ $case->value }}')" aria-pressed="{{ $status === $case->value ? 'true' : 'false' }}" @class([$pill, 'bg-ink text-canvas' => $status === $case->value, 'bg-surface-input text-ink hover:bg-hairline' => $status !== $case->value])>
                                {{ $case->label() }} <span class="tabular-nums {{ $status === $case->value ? '' : 'text-ink-subtle' }}">{{ $counts[$case->value] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <div class="mt-6 flex flex-wrap items-center gap-3">
                <label for="library-type" class="sr-only">{{ __('Format') }}</label>
                <select id="library-type" wire:model.live="type" class="{{ $control }}">
                    <option value="">{{ __('All formats') }}</option>
                    @foreach ($this->typeOptions as $option)
                        <option value="{{ $option->slug }}" wire:key="type-{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </select>

                <button
                    type="button"
                    wire:click="$toggle('favorites')"
                    aria-pressed="{{ $favorites ? 'true' : 'false' }}"
                    @class(['focus-ring inline-flex min-h-11 items-center gap-2 rounded-control px-4 text-meta', 'bg-ink text-canvas' => $favorites, 'bg-surface-input text-ink hover:bg-hairline' => ! $favorites])
                >
                    <flux:icon name="heart" :variant="$favorites ? 'solid' : 'outline'" class="size-5" />
                    <span>{{ __('Favorites') }}</span>
                </button>

                <div class="flex flex-wrap items-center gap-3 sm:ms-auto">
                    <label for="library-sort" class="sr-only">{{ __('Sort by') }}</label>
                    <select id="library-sort" wire:model.live="sort" class="{{ $control }}">
                        <option value="added">{{ __('Recently added') }}</option>
                        <option value="title">{{ __('Title A–Z') }}</option>
                        <option value="last_read">{{ __('Last read') }}</option>
                    </select>

                    <x-view-toggle wire:model.live="view" :shelves="true" />
                </div>
            </div>

            <div class="mt-6 flex min-h-11 flex-wrap items-center gap-x-6 gap-y-3">
                <p role="status" data-test="result-count" class="text-meta tabular-nums text-ink-subtle">{{ trans_choice('{0} No stories|{1} 1 story|[2,*] :count stories', $entries->total()) }}</p>

                @if ($this->chips !== [])
                    <ul aria-label="{{ __('Active filters') }}" class="flex flex-wrap items-center gap-2">
                        @foreach ($this->chips as $chip)
                            <li wire:key="chip-{{ $chip['key'] }}" data-chip class="inline-flex h-9 items-center rounded-full bg-surface-input ps-3 text-meta text-ink">
                                <span>{{ $chip['text'] }}</span>
                                <button
                                    type="button"
                                    wire:click="removeFilter('{{ $chip['key'] }}')"
                                    aria-label="{{ $chip['remove'] }}"
                                    class="focus-ring relative inline-flex size-9 items-center justify-center rounded-full text-ink-subtle after:absolute after:-inset-y-1 after:-inset-x-0.5 after:content-[''] hover:text-ink"
                                >
                                    <flux:icon name="x-mark" class="size-4" />
                                </button>
                            </li>
                        @endforeach

                        <li wire:key="chips-clear">
                            <button type="button" wire:click="clearFilters" class="focus-ring inline-flex min-h-11 items-center gap-1 rounded-control px-3 text-meta text-link hover:underline"><flux:icon name="x-mark" variant="micro" class="size-4" />{{ __('Clear all') }}</button>
                        </li>
                    </ul>
                @endif
            </div>
        @endif

        <div wire:loading.delay.shortest.class="opacity-60" class="motion-safe:transition-opacity">
            @if ($entries->isNotEmpty())
                @if ($view === 'list')
                    <ul data-view="list" class="mt-2 flex flex-col gap-4">
                        @foreach ($entries as $entry)
                            <li wire:key="entry-{{ $entry->id }}">
                                <x-result-row :media="$entry->media">
                                    <p class="flex flex-wrap items-center gap-x-2 text-meta text-ink-subtle">
                                        <flux:icon :name="$entry->status->icon()" variant="micro" class="size-4" />
                                        <span class="text-ink">{{ $entry->status->label() }}</span>
                                        @if (($percent = $underWay($entry)) !== null)
                                            <span aria-hidden="true">·</span>
                                            <span class="tabular-nums">{{ __(':percent% read', ['percent' => ReadingProgress::label($percent)]) }}</span>
                                        @endif
                                        @if ($entry->favorited_at)
                                            <flux:icon name="heart" variant="micro" class="size-4" />
                                            <span class="sr-only">{{ __('Favorite') }}</span>
                                        @endif
                                        <span aria-hidden="true">·</span>
                                        <span>{{ __('Added') }} <time datetime="{{ $entry->created_at->toIso8601String() }}" title="{{ $entry->created_at->toFormattedDateString() }}">{{ $entry->created_at->diffForHumans() }}</time></span>
                                    </p>
                                </x-result-row>
                            </li>
                        @endforeach
                    </ul>
                @elseif ($view === 'grid')
                    <ul data-view="grid" class="mt-2 grid grid-cols-2 gap-x-4 gap-y-6 sm:grid-cols-[repeat(auto-fill,minmax(11rem,1fr))]">
                        @foreach ($entries as $entry)
                            <li wire:key="entry-{{ $entry->id }}">
                                <x-cover-tile :media="$entry->media" :badge="$mixed" :progress="$underWay($entry)" fluid />
                                <p class="mt-1 flex items-center gap-1.5 text-legal text-ink-subtle">
                                    <flux:icon :name="$entry->status->icon()" variant="micro" class="size-4 shrink-0" />
                                    <span>{{ $entry->status->label() }}</span>
                                    @if ($entry->favorited_at)
                                        <flux:icon name="heart" variant="micro" class="ms-auto size-4 shrink-0 text-ink" />
                                        <span class="sr-only">{{ __('Favorite') }}</span>
                                    @endif
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @elseif ($this->libraryIsEmpty)
                <x-empty-state :title="__('Your library is empty')" class="mt-12">
                    <p class="mt-2 text-body text-ink-subtle">{{ __('Add a story with Add to Library, from its page or from any shelf, and it waits here with where you are in it.') }}</p>

                    <div class="mt-6 flex flex-wrap gap-3">
                        <x-cta icon="compass" :href="route('discover')" wire:navigate>{{ __('Browse Discover') }}</x-cta>
                        <x-cta variant="secondary" icon="magnifying-glass" :href="route('search')" wire:navigate>{{ __('Search') }}</x-cta>
                    </div>
                </x-empty-state>
            @else
                <x-empty-state :title="__('No stories match')" class="mt-12">
                    <p class="mt-2 text-body text-ink-subtle">{{ __('Nothing in your library fits these filters. Take one off, or clear them all to see everything again.') }}</p>

                    <div class="mt-6 flex flex-wrap gap-3">
                        <x-cta variant="secondary" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</x-cta>
                    </div>
                </x-empty-state>
            @endif
        </div>
    </div>

    @if ($entries->isNotEmpty() && $view === 'shelves')
        <div wire:loading.delay.shortest.class="opacity-60" class="mt-6 flex flex-col gap-4 motion-safe:transition-opacity">
            <x-class-shelves :shelves="$this->shelves" :links="false" />

            @if ($this->unshelved->isNotEmpty())
                <x-shelf :title="__('Not Yet Shelved')" :description="__('These have no class yet. They are shelved as soon as they are classified.')" id="shelf-unshelved" bleed>
                    @foreach ($this->unshelved as $media)
                        <li wire:key="unshelved-{{ $media->id }}">
                            <x-cover-tile :media="$media" />
                        </li>
                    @endforeach
                </x-shelf>
            @endif
        </div>
    @endif

    <x-pager :paginator="$entries" class="mt-8 px-gutter" />
</div>
