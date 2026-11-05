<?php

use App\Discovery\CollectionPreviews;
use App\Discovery\HomeShelves;
use App\Discovery\Recommendations\Explanation;
use App\Discovery\Recommendations\ShelfBuilder;
use App\Discovery\Shelf;
use App\Models\Collection;
use App\Models\Media;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Home')] class extends Component {
    /** The shelves whose tiles carry "Not interested" (the personal picks; Trending, New Releases, Continue Reading and the staff picks do not). */
    private const DISMISSIBLE = ['recommended', 'because-you-read', 'similar-to', 'popular-in-genres', 'discover-new'];

    /** The shelves whose tiles say why they are there; the others say it in their title or need no reason. */
    private const REASONED = ['recommended', 'popular-in-genres'];

    /**
     * Home builds its own full-width bands (the hero, the shelves), so the layout leaves `<main>` bare.
     */
    public function render(): View
    {
        return $this->view()->layoutData(['flush' => true, 'band' => $this->slides->isNotEmpty()]);
    }

    #[Computed]
    public function hasCatalog(): bool
    {
        return Media::query()->unmerged()->visibleTo(auth()->user())->exists();
    }

    /**
     * The five most trending stories that have a cover, for the hero.
     *
     * @return EloquentCollection<int, Media>
     */
    #[Computed]
    public function slides(): EloquentCollection
    {
        return app(HomeShelves::class)->hero(viewer: auth()->user());
    }

    /**
     * Every shelf of the page for this reader, in the master plan's order (see ShelfBuilder); the staff picks come with their `collection-` keys.
     *
     * @return SupportCollection<int, Shelf>
     */
    #[Computed]
    public function shelves(): SupportCollection
    {
        return app(ShelfBuilder::class)->forHome(auth()->user());
    }

    /**
     * A reader who is new (or opted out) has the staff picks moved up, where Recommended For You would be.
     */
    #[Computed]
    public function cold(): bool
    {
        return app(ShelfBuilder::class)->isCold(auth()->user());
    }

    /**
     * The getting-started card is for a new reader who has personalization on: one who turned it off chose not to be invited to pick stories.
     */
    #[Computed]
    public function starter(): bool
    {
        return auth()->user()->personalizationEnabled() && $this->cold;
    }

    /**
     * The reader's own collections for the "Your Collections" row, the most recently changed first, at most eight (See all has the rest). Exactly three get a fourth card, "New collection", in the view.
     *
     * @return EloquentCollection<int, Collection>
     */
    #[Computed]
    public function collections(): EloquentCollection
    {
        return auth()->user()->collections()->orderByDesc('updated_at')->orderByDesc('id')->limit(8)->get();
    }

    /**
     * @return array<int, array{count: int, covers: list<string>}>
     */
    #[Computed]
    public function previews(): array
    {
        return app(CollectionPreviews::class)->for($this->collections, auth()->user());
    }

    /**
     * The page as a list of blocks: `shelf`, `staff` (staff pick shelves), `mine` (their collections) and `starter` (the card). Staff picks sit after
     * Your Collections for a reader with history, and for a new one lead the page after the card, with the rest before Explore by Subject.
     *
     * @return list<array{0: string, 1?: mixed}>
     */
    #[Computed]
    public function layout(): array
    {
        $staff = $this->shelves->filter(fn (Shelf $shelf): bool => str_starts_with($shelf->key, 'collection-'))->values();
        $main = $this->shelves->reject(fn (Shelf $shelf): bool => str_starts_with($shelf->key, 'collection-'));
        $middle = $main->reject(fn (Shelf $shelf): bool => in_array($shelf->key, ['continue-reading', 'explore-by-subject'], true));
        $shelf = fn (?Shelf $shelf): array => $shelf === null ? [] : [['shelf', $shelf]];
        $picks = fn ($shelves): array => $shelves->isEmpty() ? [] : [['staff', $shelves->values()]];
        $mine = $this->collections->isEmpty() ? [] : [['mine']];

        if ($this->cold) {
            return [
                ...$shelf($main->firstWhere('key', 'continue-reading')),
                ...($this->starter ? [['starter']] : []),
                ...$picks($staff->take(1)),
                ...$middle->map(fn (Shelf $item): array => ['shelf', $item])->all(),
                ...$mine,
                ...$picks($staff->slice(1)),
                ...$shelf($main->firstWhere('key', 'explore-by-subject')),
            ];
        }

        return [
            ...$shelf($main->firstWhere('key', 'continue-reading')),
            ...$middle->map(fn (Shelf $item): array => ['shelf', $item])->all(),
            ...$mine,
            ...$picks($staff),
            ...$shelf($main->firstWhere('key', 'explore-by-subject')),
        ];
    }

    /**
     * What a tile says about why it is here, split into the lead-in and the title or genre it names.
     *
     * @return array{lead: string|null, subject: string|null}
     */
    public function reasonOf(Shelf $shelf, Media $item): array
    {
        $label = in_array($shelf->key, self::REASONED, true) ? $item->getAttribute('reason_label') : null;

        return $label === null ? ['lead' => null, 'subject' => null] : Explanation::parts($label);
    }

    public function dismissible(Shelf $shelf, Media $item): bool
    {
        return in_array($shelf->key, self::DISMISSIBLE, true) && $item->getAttribute('recommendation_id') !== null;
    }

    /**
     * Where "See all" goes for a shelf, or null where there is no stable page behind it (Recommended For You, Similar To and Discover Something New are ranked or random per reader).
     */
    public function seeAll(Shelf $shelf): ?string
    {
        return match ($shelf->key) {
            'continue-reading' => route('library.index', ['status' => 'reading', 'sort' => 'last_read']),
            'because-you-read' => $shelf->href,
            'new-releases', 'trending', 'popular-in-genres' => route('discover'),
            'explore-by-subject' => route('explore.index'),
            default => null,
        };
    }
}; ?>

<div
    x-data="recommendationTiles(@js([
        'token' => csrf_token(),
        'clickUrl' => route('recommendations.click'),
        'impressionsUrl' => route('recommendations.impressions'),
        'dismissUrl' => route('recommendations.dismiss', '__id__'),
        'undoUrl' => route('recommendations.undo', '__id__'),
        'undoSeconds' => 10,
        'messages' => ['removed' => __('Removed. We won\'t suggest this again.'), 'failed' => __('That did not work. The story is back; try again in a moment.')],
    ]))"
    @click="track($event)"
    @auxclick="track($event)"
    @click.window="outside($event)"
    @keydown.escape.capture="menuId !== null && ($event.stopPropagation(), $event.preventDefault(), closeMenu(true))"
>
    @if ($this->hasCatalog)
        @if ($this->slides->isNotEmpty())
            <x-hero :slides="$this->slides" bleed />
        @else
            <h1 class="sr-only">{{ __('Home') }}</h1>
        @endif

        <x-search-panel :action="route('search')" :placeholder="__('Titles, authors, series and subjects')" class="my-12 px-gutter lg:my-16" />

        @foreach ($this->layout as $block)
            @switch($block[0])
                @case('starter')
                    {{-- A guide, not a gate: one sentence on what to do and one quiet button, shown only while Home has little to go on. --}}
                    <section aria-labelledby="getting-started" data-getting-started class="mx-gutter mb-12 flex flex-col gap-4 rounded-card bg-surface-deep p-4 sm:flex-row sm:items-center sm:justify-between sm:p-6 lg:mb-16">
                        <div class="min-w-0 max-w-prose">
                            <p class="text-legal uppercase tracking-widest text-ink-subtle">{{ __('New here') }}</p>
                            <h2 id="getting-started" class="text-lead text-ink">{{ __('Home is just getting started') }}</h2>
                            <p class="mt-1 text-meta text-ink-subtle">{{ __('Pick a few stories you love and Home will start suggesting books for you. Until then, here is what other readers are enjoying.') }}</p>
                        </div>
                        <x-cta variant="secondary" :href="route('onboarding', ['step' => 3])" wire:navigate class="shrink-0">{{ __('Choose stories') }}</x-cta>
                    </section>
                    @break

                @case('staff')
                    <x-editorial-shelves :shelves="$block[1]" :eyebrow="__('Picked by our editors')" bleed />
                    @break

                @case('mine')
                    <div>
                        <x-shelf :title="__('Your Collections')" :href="route('collections.index')" id="shelf-your-collections" bleed>
                            @foreach ($this->collections as $collection)
                                <li wire:key="your-collection-{{ $collection->id }}">
                                    <x-collection-card :collection="$collection" :preview="$this->previews[$collection->id]" />
                                </li>
                            @endforeach
                            @if ($this->collections->count() === 3)
                                <li wire:key="your-collections-new"><x-collection-new-card /></li>
                            @endif
                        </x-shelf>
                    </div>
                    @break

                @default
                    @php $shelf = $block[1]; @endphp

                    {{-- The shelves are bands that butt together: each brings its own padding, so there is no strip of bare canvas between two of them. --}}
                    <div>
                        @if ($shelf->key === 'explore-by-subject')
                            <x-shelf :title="$shelf->title" :href="$this->seeAll($shelf)" id="shelf-explore-by-subject" bleed>
                                @foreach ($shelf->items as $item)
                                    <li wire:key="explore-by-subject-{{ $item->id }}"><x-subject-tile :dewey-class="$item" /></li>
                                @endforeach
                            </x-shelf>
                        @elseif ($shelf->key === 'continue-reading')
                            <x-shelf :title="$shelf->title" :description="$shelf->description" :href="$this->seeAll($shelf)" id="shelf-continue-reading" previews="quick-view-continue-reading" bleed>
                                @foreach ($shelf->items as $item)
                                    @php $where = filled($item->progress_chapter) ? __('Chapter :chapter', ['chapter' => $item->progress_chapter]) : ($item->progress_page ? __('Page :page', ['page' => $item->progress_page]) : null); @endphp
                                    <li wire:key="continue-reading-{{ $item->id }}">
                                        <x-story-preview :media="$item">
                                            <x-cover-tile :media="$item" :progress="$item->progress_percent" :reason="$where" />
                                        </x-story-preview>
                                    </li>
                                @endforeach
                            </x-shelf>
                        @else
                            <x-shelf :title="$shelf->title" :href="$this->seeAll($shelf)" :featured="$shelf->featured" :id="'shelf-'.$shelf->key" :previews="'quick-view-'.$shelf->key" bleed>
                                @foreach ($shelf->items as $item)
                                    @php $reason = $this->reasonOf($shelf, $item); @endphp
                                    <li wire:key="{{ $shelf->key }}-{{ $item->id }}">
                                        <x-story-preview :media="$item">
                                            <x-cover-tile
                                                :media="$item"
                                                :recommendation="$item->recommendation_id"
                                                :dismissible="$this->dismissible($shelf, $item)"
                                                :reason="$reason['lead']"
                                                :reason-subject="$reason['subject']"
                                            />
                                        </x-story-preview>
                                    </li>
                                @endforeach
                            </x-shelf>
                        @endif
                    </div>
            @endswitch
        @endforeach
    @else
        <div class="px-gutter pt-12">
            <x-empty-catalog heading="h1" />
        </div>
    @endif

    {{-- "Not interested" on a phone or touch screen: a bottom sheet naming the story. Its dialog shuts on Esc, on Cancel and on a press on the dimmed page. --}}
    <dialog
        x-ref="sheet"
        aria-labelledby="tile-sheet-title"
        @click="$event.target === $el && closeSheet()"
        class="m-0 mt-auto w-full max-w-none rounded-t-card border-t border-hairline bg-surface-card p-0 text-ink backdrop:bg-canvas/70"
    >
        <div class="px-gutter pb-6 pt-4">
            <h2 id="tile-sheet-title" class="line-clamp-2 text-lead text-ink" x-text="sheetTitle"></h2>
            <button type="button" @click="notInterested()" class="focus-ring mt-4 flex min-h-14 w-full items-center gap-4 rounded-control border-t border-hairline py-3 text-start">
                <flux:icon name="eye-slash" class="size-5 shrink-0" />
                <span>
                    <span class="block text-body text-ink">{{ __('Not interested') }}</span>
                    <span class="block text-meta text-ink-subtle">{{ __('We won\'t suggest this again') }}</span>
                </span>
            </button>
            <button type="button" @click="closeSheet()" class="focus-ring mt-2 min-h-11 w-full rounded-control text-center text-body font-medium text-ink hover:bg-surface-input">{{ __('Cancel') }}</button>
        </div>
    </dialog>

    {{-- The undo toast: a status, not a dialog, so it never takes focus. It stays for the undo window and waits while it is hovered or has focus. --}}
    <div
        role="status"
        class="pointer-events-none fixed inset-x-4 bottom-24 z-50 flex justify-center lg:bottom-6"
        @mouseenter="pauseToast()" @mouseleave="resumeToast()" @focusin="pauseToast()" @focusout="resumeToast()"
    >
        <div x-show="message" style="display: none" class="pointer-events-auto flex w-full max-w-lg items-center gap-3 rounded-control border border-hairline bg-surface-card py-2 ps-4 pe-2 text-meta text-ink shadow-overlay">
            <flux:icon name="check" class="size-5 shrink-0 text-ink" />
            <span class="min-w-0 flex-1" x-text="message"></span>
            <button type="button" x-show="canUndo" @click="undo()" class="focus-ring inline-flex min-h-11 items-center rounded-control px-3 font-medium text-link hover:underline">{{ __('Undo') }}</button>
            <button type="button" @click="hideToast()" aria-label="{{ __('Close') }}" class="focus-ring inline-flex size-11 items-center justify-center rounded-control text-ink hover:bg-surface-input">
                <flux:icon name="x-mark" class="size-5" />
            </button>
        </div>
    </div>
</div>
