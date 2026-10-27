<?php

use App\Models\Creator;
use App\Models\Media;
use App\Models\Series;
use Illuminate\Contracts\View\View;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Series')] class extends Component {
    use WithPagination;

    #[Locked]
    public int $seriesId;

    /**
     * An unknown slug is a 404.
     */
    public function mount(string $slug): void
    {
        $this->seriesId = Series::query()->where('slug', $slug)->firstOrFail()->id;
    }

    /**
     * The header is a band across the whole page, so the layout leaves `<main>` bare (see layouts/app).
     */
    public function render(): View
    {
        return $this->view()->title($this->series->title)->layoutData(['flush' => true]);
    }

    #[Computed]
    public function series(): Series
    {
        return Series::query()->findOrFail($this->seriesId);
    }

    /**
     * The volumes on this page, 100 to a page, in reading order (merged-away and deleted ones are left out).
     */
    #[Computed]
    public function volumes(): LengthAwarePaginator
    {
        return $this->series->orderedMedia()->unmerged()->paginate(100);
    }

    /**
     * How many live volumes the series has and the years they span, in one query (the header and the "About" column read it whatever page is open).
     *
     * @return array{count: int, years: string|null}
     */
    #[Computed]
    public function summary(): array
    {
        $row = $this->series->media()->unmerged()->toBase()->selectRaw('count(*) as volumes, min(publication_year) as first_year, max(publication_year) as last_year')->first();

        $years = match (true) {
            $row->first_year === null => null,
            $row->first_year === $row->last_year => (string) $row->first_year,
            default => $row->first_year.' – '.$row->last_year,
        };

        return ['count' => (int) $row->volumes, 'years' => $years];
    }

    /**
     * The first volumes in reading order, which the header stacks. The first one also tints the band.
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function stack(): Collection
    {
        return $this->series->orderedMedia()->unmerged()->limit(4)->get();
    }

    /**
     * The people behind the volumes with the role they hold, each once: author and illustrator credits in the order they first appear.
     *
     * @return \Illuminate\Support\Collection<int, Creator>
     */
    #[Computed]
    public function creators(): \Illuminate\Support\Collection
    {
        return Creator::query()
            ->join('media_creators', 'media_creators.creator_id', '=', 'creators.id')
            ->whereIn('media_creators.media_id', $this->series->media()->unmerged()->select('media.id'))
            ->groupBy('creators.id', 'media_creators.role')
            ->orderByRaw('min(media_creators.position)')
            ->orderBy('creators.name')
            ->limit(6)
            ->get(['creators.*', 'media_creators.role as credit']);
    }
}; ?>

@php
    $series = $this->series;
    $volumes = $this->volumes;
    $stack = $this->stack;
    $first = $stack->first();
    $summary = $this->summary;
    $startLabel = $first && (float) $first->pivot->position === 1.0 ? __('See volume 1') : __('See the first volume');
    $status = $series->status->value !== 'unknown' ? __(ucfirst($series->status->value)) : null;
    // A short blurb stays in the header; a long one moves to "About this series" so the header keeps its height.
    $longDescription = filled($series->description) && Str::length($series->description) > 180;
    $hasAbout = $longDescription || $this->creators->isNotEmpty() || $status || $summary['years'];
    // The fan of books: where each one stands (size, left, top, brightness). The first is the three.js book on capable devices.
    $fan = [
        ['size' => 'xl', 'class' => 'left-0 top-0 z-[4]'],
        ['size' => 'lg', 'class' => 'left-[12.5rem] top-[3rem] z-[3] brightness-[.7] saturate-[.85]'],
        ['size' => 'md', 'class' => 'left-[20.5rem] top-[6rem] z-[2] brightness-[.55] saturate-[.8]'],
        ['size' => 'sm', 'class' => 'left-[27rem] top-[8.5rem] z-[1] brightness-[.45] saturate-[.75]'],
    ];
@endphp

<div>
    <header class="relative isolate overflow-hidden bg-surface-deep">
        @if ($first?->cover_url)
            <img src="{{ $first->cover_url }}" alt="" width="224" height="336" decoding="async" aria-hidden="true" class="absolute inset-0 -z-20 size-full scale-125 object-cover opacity-50 blur-3xl">
        @endif
        {{-- The scrim keeps the text over the tinted band above AA contrast whatever the cover is. --}}
        <div aria-hidden="true" class="absolute inset-0 -z-10 bg-linear-to-t from-canvas via-canvas/75 to-canvas/60 lg:bg-linear-to-r lg:from-canvas/90 lg:via-canvas/75 lg:to-canvas/50"></div>

        <div class="flex flex-col gap-8 px-gutter py-8 lg:flex-row lg:items-center lg:gap-16 lg:py-16">
            {{-- The first volumes as a staggered stack, spine towards the reader; scaled down on a phone. --}}
            <div data-series-stack class="relative h-[calc(27rem*.55)] w-[calc(32rem*.55)] shrink-0 sm:h-[calc(27rem*.8)] sm:w-[calc(32rem*.8)] lg:h-[27rem] lg:w-[32rem]">
                @if ($stack->isNotEmpty())
                    <div class="absolute inset-0 origin-top-left scale-[.55] sm:scale-[.8] lg:scale-100">
                        @foreach ($stack as $index => $volume)
                            <div wire:key="stack-{{ $volume->id }}" class="absolute {{ $fan[$index]['class'] }}">
                                <x-book-3d :media="$volume" :size="$fan[$index]['size']" tilt="28deg" :title="$volume->title" :scene="$index === 0" :interactive="$index === 0" :priority="$index === 0" />
                            </div>
                        @endforeach
                    </div>
                @else
                    <span class="block aspect-[2/3] w-40 overflow-hidden rounded-card bg-surface-card sm:w-56">
                        <x-cover-fallback :title="$series->title" />
                    </span>
                @endif
            </div>

            <div x-data x-motion-reveal data-stagger="60" class="min-w-0 flex-1">
                <p class="text-legal uppercase tracking-widest text-ink-subtle">{{ __('Series') }}</p>
                <h1 class="mt-2 line-clamp-3 break-words text-section tracking-tight text-balance text-ink md:text-title">{{ $series->title }}</h1>
                @if ($summary['count'] > 0)
                    <p class="mt-2 text-body text-ink-subtle tabular-nums">{{ trans_choice(':count volume|:count volumes', $summary['count']) }}</p>
                @endif

                <p class="mt-2 max-w-prose text-body text-ink-subtle text-pretty">{{ __('Every volume in this series, in reading order. Start with the first, or open any volume to read more.') }}</p>

                @if (filled($series->description) && ! $longDescription)
                    <p class="mt-2 max-w-prose text-body text-ink-subtle">{{ $series->description }}</p>
                @endif

                @if ($first)
                    <div class="mt-6">
                        <x-cta icon="book-open" :href="route('stories.show', $first->slug)" wire:navigate>{{ $startLabel }}</x-cta>
                    </div>
                @endif
            </div>
        </div>
    </header>

    <div class="px-gutter pt-12 lg:pt-16">
        <div @class(['flex flex-col lg:grid lg:grid-cols-[minmax(0,48rem)_18rem] lg:gap-16' => $hasAbout])>
            <div class="min-w-0">
                <h2 class="text-section text-ink">{{ __('Volumes') }}</h2>
                <p class="mt-1 text-meta text-ink-subtle">{{ __('In reading order. Select a volume to open its page.') }}</p>

                @if ($volumes->isNotEmpty())
                    <ul class="mt-4 divide-y divide-hairline">
                        @foreach ($volumes as $volume)
                            <li wire:key="volume-{{ $volume->id }}">
                                <x-series-item :media="$volume" :position="$volume->pivot->position" :label="$volume->pivot->label" aligned class="py-2" />
                            </li>
                        @endforeach
                    </ul>

                    @if ($volumes->hasPages())
                        <x-pager :paginator="$volumes" class="mt-8" />
                    @endif
                @else
                    <div class="mt-4 max-w-prose">
                        <p class="text-lead text-ink">{{ __('No volumes yet') }}</p>
                        <p class="mt-2 text-body text-ink-subtle">{{ __('This series is listed, but none of its volumes have reached the catalog. They appear here as soon as they are added.') }}</p>
                        <x-cta variant="secondary" icon="building-library" :href="route('explore.index')" wire:navigate class="mt-6">{{ __('Explore the Library') }}</x-cta>
                    </div>
                @endif
            </div>

            @if ($hasAbout)
                <aside aria-labelledby="series-about" class="mt-12 min-w-0 lg:mt-0">
                    <h2 id="series-about" class="text-lead text-ink">{{ __('About This Series') }}</h2>

                    @if ($longDescription)
                        <x-clamped-text :lines="6" class="mt-2">{{ $series->description }}</x-clamped-text>
                    @endif

                    @if ($this->creators->isNotEmpty())
                        <ul aria-label="{{ __('Creators') }}" class="mt-3 flex flex-wrap gap-x-4 text-body text-ink">
                            @foreach ($this->creators as $creator)
                                <li wire:key="creator-{{ $creator->id }}-{{ $creator->credit }}" class="flex items-baseline gap-2">
                                    <a href="{{ route('search', ['q' => $creator->name]) }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center rounded-control hover:underline">{{ $creator->name }}</a>
                                    <span class="text-legal text-ink-subtle">{{ Str::headline($creator->credit) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($status || $summary['years'])
                        <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-6 gap-y-1 text-meta">
                            @if ($status)
                                <dt class="text-ink-subtle">{{ __('Status') }}</dt>
                                <dd class="text-ink">{{ $status }}</dd>
                            @endif
                            @if ($summary['years'])
                                <dt class="text-ink-subtle">{{ __('Published') }}</dt>
                                <dd class="tabular-nums text-ink">{{ $summary['years'] }}</dd>
                            @endif
                        </dl>
                    @endif
                </aside>
            @endif
        </div>
    </div>
</div>
