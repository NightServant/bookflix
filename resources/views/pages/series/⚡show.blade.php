<?php

use App\Models\Media;
use App\Models\Series;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Series')] class extends Component {
    #[Locked]
    public int $seriesId;

    /**
     * An unknown slug is a 404.
     */
    public function mount(string $slug): void
    {
        $this->seriesId = Series::query()->where('slug', $slug)->firstOrFail()->id;
    }

    public function render(): View
    {
        return $this->view()->title($this->series->title);
    }

    #[Computed]
    public function series(): Series
    {
        return Series::query()->findOrFail($this->seriesId);
    }

    /**
     * The live volumes in reading order (merged-away and deleted ones are left out), each with the creators the header credits.
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function volumes(): Collection
    {
        return $this->series->orderedMedia()->unmerged()->with('creators')->get();
    }

    /**
     * The people behind the volumes, each named once: the author and illustrator credits in the order they first appear.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Creator>
     */
    #[Computed]
    public function creators(): \Illuminate\Support\Collection
    {
        return $this->volumes
            ->flatMap(fn (Media $volume) => $volume->creators->sortBy('pivot.position'))
            ->unique('id')
            ->values();
    }

    /**
     * "1994 – 2001", or one year when they all share it, or null when no volume has a year.
     */
    #[Computed]
    public function years(): ?string
    {
        $years = $this->volumes->pluck('publication_year')->filter();

        if ($years->isEmpty()) {
            return null;
        }

        return $years->min() === $years->max() ? (string) $years->min() : $years->min().' – '.$years->max();
    }
}; ?>

@php
    $series = $this->series;
    $volumes = $this->volumes;
    $first = $volumes->first();
    $startLabel = $first && (float) $first->pivot->position === 1.0 ? __('Start with volume 1') : __('Start reading');
    $facts = collect([
        $volumes->isNotEmpty() ? trans_choice(':count volume|:count volumes', $volumes->count()) : null,
        $this->years,
        $series->status->value !== 'unknown' ? ucfirst($series->status->value) : null,
    ])->filter();
@endphp

<section class="pt-2 pb-12 lg:pt-6">
    <header class="relative isolate overflow-hidden rounded-card bg-surface-deep">
        @if ($first?->cover_url)
            <img src="{{ $first->cover_url }}" alt="" width="224" height="336" decoding="async" aria-hidden="true" class="absolute inset-0 -z-20 size-full scale-125 object-cover opacity-60 blur-3xl">
        @endif
        <div aria-hidden="true" class="absolute inset-0 -z-10 bg-linear-to-t from-canvas via-canvas/50 to-canvas/10"></div>

        <div x-data x-motion-reveal data-stagger="60" class="flex flex-col gap-8 p-4 pt-8 sm:p-8 lg:flex-row lg:items-end lg:gap-12 lg:p-12 lg:pt-16">
            <span class="block aspect-[2/3] w-40 shrink-0 overflow-hidden rounded-card bg-surface-card sm:w-56">
                @if ($first?->cover_url)
                    <img src="{{ $first->cover_url }}" alt="{{ __('Cover of :title', ['title' => $first->title]) }}" width="224" height="336" fetchpriority="high" decoding="async" class="size-full object-cover">
                @else
                    <x-cover-fallback :title="$series->title" />
                @endif
            </span>

            <div class="min-w-0 flex-1">
                <p class="text-legal uppercase tracking-widest text-ink-subtle">{{ __('Series') }}</p>
                <h1 class="mt-2 text-section tracking-tight text-ink md:text-hero">{{ $series->title }}</h1>

                @if ($facts->isNotEmpty())
                    <ul class="mt-3 flex flex-wrap items-center text-body text-ink-subtle">
                        @foreach ($facts as $fact)
                            <li class="tabular-nums [&:not(:last-child)]:after:mx-2 [&:not(:last-child)]:after:content-['·']">{{ $fact }}</li>
                        @endforeach
                    </ul>
                @endif

                @if ($this->creators->isNotEmpty())
                    <ul aria-label="{{ __('Creators') }}" class="mt-1 flex flex-wrap gap-x-4 text-body text-ink">
                        @foreach ($this->creators->take(4) as $creator)
                            <li wire:key="creator-{{ $creator->id }}">
                                <a href="{{ route('search', ['q' => $creator->name]) }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center rounded-control hover:underline">{{ $creator->name }}</a>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if (filled($series->description))
                    <p class="mt-2 max-w-prose text-body text-ink-subtle">{{ $series->description }}</p>
                @endif

                @if ($first)
                    <div class="mt-6">
                        <x-cta :href="route('stories.show', $first->slug)" wire:navigate>{{ $startLabel }}</x-cta>
                    </div>
                @endif
            </div>
        </div>
    </header>

    <div class="mt-12 max-w-3xl lg:mt-16">
        <h2 class="text-section text-ink">{{ __('Volumes') }}</h2>

        @if ($volumes->isNotEmpty())
            <ul class="mt-4 divide-y divide-hairline">
                @foreach ($volumes as $volume)
                    <li wire:key="volume-{{ $volume->id }}">
                        <x-series-item :media="$volume" :position="$volume->pivot->position" :label="$volume->pivot->label" aligned class="py-3" />
                    </li>
                @endforeach
            </ul>
        @else
            <div class="mt-4 max-w-prose">
                <p class="text-lead text-ink">{{ __('No volumes yet') }}</p>
                <p class="mt-2 text-body text-ink-subtle">{{ __('This series is listed, but none of its volumes have reached the catalog. They appear here as soon as they are added.') }}</p>
                <x-cta variant="secondary" :href="route('explore.index')" wire:navigate class="mt-6">{{ __('Explore the Library') }}</x-cta>
            </div>
        @endif
    </div>
</section>
