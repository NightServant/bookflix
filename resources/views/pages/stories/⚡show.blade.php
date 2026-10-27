<?php

use App\Discovery\SimilarMedia;
use App\Enums\ClassificationType;
use App\Models\DeweyClass;
use App\Models\ExternalSource;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use App\Models\Series;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Story')] class extends Component {
    #[Locked]
    public int $mediaId;

    /**
     * A merged-away slug answers 301 to the story it became (keeping the query string);
     * an unknown or deleted one is a 404.
     */
    public function mount(string $slug): void
    {
        $media = Media::resolveSlug($slug) ?? abort(404);

        if ($media->slug !== $slug) {
            abort(redirect()->route('stories.show', [...request()->query(), 'slug' => $media->slug], 301));
        }

        $this->mediaId = $media->id;
    }

    public function render(): View
    {
        return $this->view()->title($this->media->title)->layoutData(['flush' => true]);
    }

    /**
     * The story with everything the page reads, so nothing lazy loads. The classes a reader may see (accepted, or pending with
     * enough confidence) come in one go, primary first, and double as the `publicPrimaryDewey` that <x-story-info> reads.
     */
    #[Computed]
    public function media(): Media
    {
        $media = Media::query()->with([
            'mediaType',
            'creators',
            'publishers',
            'genres',
            'themes',
            'tags',
            'series.orderedMedia',
            'relations',
            'externalIds.externalSource',
        ])->findOrFail($this->mediaId);

        $classes = $media->deweyClasses()->public()->with('deweyClass.parent')->orderBy('classification_type')->orderBy('id')->get();

        return $media
            ->setRelation('publicDeweyClasses', $classes)
            ->setRelation('publicPrimaryDewey', $classes->first(fn (MediaDeweyClass $class): bool => $class->classification_type === ClassificationType::Primary));
    }

    /**
     * @return Collection<int, MediaDeweyClass>
     */
    #[Computed]
    public function classes(): Collection
    {
        return $this->media->getRelation('publicDeweyClasses');
    }

    #[Computed]
    public function primaryClass(): ?DeweyClass
    {
        return $this->media->publicPrimaryDewey?->deweyClass;
    }

    /**
     * The classes beyond the primary one: the primary is the breadcrumb, so only these are listed, under "Also shelved in".
     *
     * @return Collection<int, MediaDeweyClass>
     */
    #[Computed]
    public function secondaryClasses(): Collection
    {
        return $this->classes->filter(fn (MediaDeweyClass $class): bool => $class->classification_type !== ClassificationType::Primary)->values();
    }

    /**
     * The story before and after this one in its first series, or null for a standalone story.
     *
     * @return array{series: Series, previous: Media|null, next: Media|null}|null
     */
    #[Computed]
    public function neighbours(): ?array
    {
        $series = $this->media->series->first();

        if ($series === null) {
            return null;
        }

        $volumes = $series->orderedMedia->values();
        $index = $volumes->search(fn (Media $volume): bool => $volume->is($this->media));

        return [
            'series' => $series,
            'previous' => $index === false ? null : $volumes->get($index - 1),
            'next' => $index === false ? null : $volumes->get($index + 1),
        ];
    }

    /**
     * @return Collection<int, Media>
     */
    #[Computed]
    public function similar(): Collection
    {
        return app(SimilarMedia::class)->for($this->media);
    }

    /**
     * The providers this story's data came from that want to be named, once each, with the link back when they ask for one.
     *
     * @return \Illuminate\Support\Collection<int, array{source: ExternalSource, url: string|null}>
     */
    #[Computed]
    public function attributions(): \Illuminate\Support\Collection
    {
        return $this->media->externalIds
            ->filter(fn ($externalId): bool => filled($externalId->externalSource->attribution) && ($externalId->externalSource->display_policy['show_attribution'] ?? true))
            ->unique('external_source_id')
            ->map(fn ($externalId): array => [
                'source' => $externalId->externalSource,
                'url' => ($externalId->externalSource->display_policy['link_back'] ?? false) ? $externalId->source_url : null,
            ])
            ->values();
    }
}; ?>

@php
    $media = $this->media;
    $creators = $media->creators->sortBy('pivot.position');
    $neighbours = $this->neighbours;
    $tabs = ['overview' => __('Overview')]
        + ($media->series->isNotEmpty() ? ['series' => __('Series')] : [])
        + ($media->relations->isNotEmpty() ? ['related' => __('Related')] : []);
    // Until the series page exists (Phase 4.5) a series is named, not linked.
    $linkSeries = Route::has('series.show');
@endphp

<div x-data>
    <header class="relative isolate overflow-hidden bg-surface-deep">
        @if ($media->cover_url)
            <img src="{{ $media->cover_url }}" alt="" width="256" height="384" decoding="async" aria-hidden="true" class="absolute inset-0 -z-20 size-full scale-125 object-cover opacity-50 blur-3xl">
        @endif
        {{-- The scrim keeps the text over the tinted band above AA contrast whatever the cover is. --}}
        <div aria-hidden="true" class="absolute inset-0 -z-10 bg-linear-to-t from-canvas via-canvas/75 to-canvas/60 lg:bg-linear-to-r lg:from-canvas/90 lg:via-canvas/75 lg:to-canvas/50"></div>

        <div class="px-gutter py-8 lg:py-16">
            @if ($this->primaryClass)
                <x-dewey-breadcrumb :dewey-class="$this->primaryClass" />
            @endif

            <div class="mt-6 flex flex-col gap-8 lg:flex-row lg:items-center lg:gap-16">
                <div class="relative h-[19.5rem] w-[14.5rem] shrink-0 ps-3 sm:h-[26rem] sm:w-[19rem] lg:h-[27.5rem] lg:w-[20rem]">
                    <div class="origin-top-left scale-75 sm:scale-100">
                        <x-book-3d :media="$media" size="xl" tilt="24deg" interactive scene :title="$media->title" />
                    </div>
                </div>

                <div x-motion-reveal data-stagger="60" class="min-w-0 flex-1">
                    <h1 class="line-clamp-3 text-section tracking-tight text-balance text-ink md:text-title">{{ $media->title }}</h1>
                    <p class="mt-2 max-w-prose text-body text-ink-subtle text-pretty">{{ __('Read the summary and see where this story sits in the library. Follow a genre, theme or class to find more like it.') }}</p>

                    @if ($media->alternative_titles !== [])
                        <p class="mt-1 line-clamp-2 max-w-prose text-meta text-ink-subtle">{{ __('Also known as') }} {{ implode(', ', $media->alternative_titles) }}</p>
                    @endif

                    @if ($creators->isNotEmpty())
                        <ul aria-label="{{ __('Creators') }}" class="mt-3 flex flex-wrap gap-x-4 text-body text-ink">
                            @foreach ($creators as $creator)
                                <li wire:key="creator-{{ $creator->id }}-{{ $creator->pivot->role->value }}" class="flex items-baseline gap-2">
                                    <a href="{{ route('search', ['q' => $creator->name]) }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center rounded-control hover:underline">{{ $creator->name }}</a>
                                    <span class="text-legal text-ink-subtle">{{ Str::headline($creator->pivot->role->value) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($media->publishers->isNotEmpty())
                        <p class="text-meta text-ink-subtle">{{ __('Published by') }} {{ $media->publishers->pluck('name')->implode(', ') }}</p>
                    @endif

                    <x-story-info :media="$media" :class-code="false" class="mt-3" />

                    <x-rating-display :average="$media->average_rating" :count="$media->ratings_count" class="mt-3" />

                    @if (filled($media->description))
                        <x-clamped-text class="mt-4">{{ $media->description }}</x-clamped-text>
                    @endif

                    @if (Route::has('library.store') || ($neighbours && $linkSeries))
                        <div class="mt-6 flex flex-wrap gap-3">
                            @if (Route::has('library.store'))
                                <x-cta icon="plus" @click="$dispatch('library-add', { id: {{ $media->id }} })">{{ __('Add to Library') }}</x-cta>
                            @endif

                            @if ($neighbours && $linkSeries)
                                <x-cta :variant="Route::has('library.store') ? 'secondary' : 'primary'" icon="rectangle-stack" :href="route('series.show', $neighbours['series']->slug)" wire:navigate>{{ __('See the series') }}</x-cta>
                            @endif
                        </div>
                    @endif

                    @if ($neighbours)
                        @unless ($linkSeries)
                            <p class="mt-6 text-meta text-ink-subtle">{{ __('Part of') }} <span class="text-ink">{{ $neighbours['series']->title }}</span></p>
                        @endunless

                        @if ($neighbours['previous'] || $neighbours['next'])
                            <nav aria-label="{{ __('Series navigation') }}" class="mt-2 flex flex-wrap gap-x-6">
                                @if ($neighbours['previous'])
                                    <a href="{{ route('stories.show', $neighbours['previous']->slug) }}" wire:navigate rel="prev" class="focus-ring inline-flex min-h-11 items-center gap-1 rounded-control text-body text-link hover:underline">
                                        <flux:icon name="chevron-left" variant="micro" />
                                        <span><span class="text-ink-subtle">{{ __('Previous in series') }}</span> {{ $neighbours['previous']->title }}</span>
                                    </a>
                                @endif

                                @if ($neighbours['next'])
                                    <a href="{{ route('stories.show', $neighbours['next']->slug) }}" wire:navigate rel="next" class="focus-ring inline-flex min-h-11 items-center gap-1 rounded-control text-body text-link hover:underline">
                                        <span><span class="text-ink-subtle">{{ __('Next in series') }}</span> {{ $neighbours['next']->title }}</span>
                                        <flux:icon name="chevron-right" variant="micro" />
                                    </a>
                                @endif
                            </nav>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </header>

    <div class="px-gutter">
    <x-tabs :tabs="$tabs" :label="__('Story sections')" class="mt-12 lg:mt-16">
        <x-slot:overview>
            <div class="flex max-w-prose flex-col gap-8">
                @if ($this->secondaryClasses->isNotEmpty())
                    <section>
                        <h2 class="text-lead text-ink">{{ __('Also shelved in') }}</h2>
                        <p class="mt-1 text-meta text-ink-subtle">{{ __('Other places this story fits. Open a class to see its neighbours.') }}</p>
                        <ul class="mt-2 flex flex-col">
                            @foreach ($this->secondaryClasses as $class)
                                <li wire:key="class-{{ $class->id }}">
                                    <a href="{{ route('explore.show', $class->deweyClass->code) }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center gap-2 rounded-control text-body text-link hover:underline">
                                        <span class="tabular-nums">{{ $class->deweyClass->code }}</span>
                                        <span>{{ $class->deweyClass->name }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <div class="flex flex-wrap gap-x-10 gap-y-8">
                @foreach ([
                    'genres' => [__('Genres'), $media->genres, fn ($genre) => route('search', ['filters' => ['genres' => [$genre->slug]]])],
                    'themes' => [__('Themes'), $media->themes, fn ($theme) => route('search', ['filters' => ['themes' => [$theme->slug]]])],
                    'tags' => [__('Tags'), $media->tags, fn ($tag) => route('search', ['q' => $tag->name])],
                ] as $group => [$heading, $items, $link])
                    @if ($items->isNotEmpty())
                        <section>
                            <h2 class="text-lead text-ink">{{ $heading }}</h2>
                            <ul class="mt-2 flex flex-wrap gap-2">
                                @foreach ($items as $item)
                                    <li wire:key="{{ $group }}-{{ $item->id }}">
                                        <x-chip :href="$link($item)" wire:navigate>{{ $item->name }}</x-chip>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                @endforeach
                </div>

                @if ($media->content_warnings !== [])
                    <x-accordion :title="__('Content warnings')">
                        <ul class="list-disc ps-6">
                            @foreach ($media->content_warnings as $warning)
                                <li wire:key="warning-{{ $loop->index }}">{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </x-accordion>
                @endif
            </div>
        </x-slot:overview>

        @if ($media->series->isNotEmpty())
            <x-slot:series>
                <div class="flex max-w-prose flex-col gap-8">
                    @foreach ($media->series as $series)
                        <section wire:key="series-{{ $series->id }}">
                            <h2 class="text-lead text-ink">
                                @if ($linkSeries)
                                    <a href="{{ route('series.show', $series->slug) }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center rounded-control hover:underline">{{ $series->title }}</a>
                                @else
                                    {{ $series->title }}
                                @endif
                            </h2>

                            @php
                                // Five volumes either side of this one; the series page has the rest.
                                $volumes = $series->orderedMedia->values();
                                $at = $volumes->search(fn ($volume) => $volume->is($media));
                                $window = $at === false ? $volumes->take(11) : $volumes->slice(max(0, $at - 5), min($at, 5) + 6);
                            @endphp

                            <ul class="mt-2">
                                @foreach ($window as $volume)
                                    <li wire:key="volume-{{ $series->id }}-{{ $volume->id }}">
                                        <x-series-item :media="$volume" :position="$volume->pivot->position" :label="$volume->pivot->label" :aria-current="$volume->is($media) ? 'true' : null" />
                                    </li>
                                @endforeach
                            </ul>

                            @if ($linkSeries && $volumes->count() > $window->count())
                                <a href="{{ route('series.show', $series->slug) }}" wire:navigate class="focus-ring mt-2 inline-flex min-h-11 items-center gap-1 rounded-control text-meta text-link hover:underline">
                                    {{ trans_choice('All :count volume|All :count volumes', $volumes->count()) }}<flux:icon name="arrow-right" variant="micro" class="size-4" />
                                </a>
                            @endif
                        </section>
                    @endforeach
                </div>
            </x-slot:series>
        @endif

        @if ($media->relations->isNotEmpty())
            <x-slot:related>
                <div class="max-w-prose">
                    <h2 class="text-lead text-ink">{{ __('Related stories') }}</h2>
                    <p class="mt-1 text-meta text-ink-subtle">{{ __('Sequels, spin-offs and other titles connected to this one.') }}</p>

                    <ul class="mt-2">
                        @foreach ($media->relations as $related)
                            <li wire:key="related-{{ $related->id }}-{{ $related->pivot->relation }}">
                                <x-series-item :media="$related" :label="Str::headline($related->pivot->relation)" />
                            </li>
                        @endforeach
                    </ul>
                </div>
            </x-slot:related>
        @endif
    </x-tabs>

    </div>

    @if ($this->similar->isNotEmpty())
        <x-shelf bleed :title="__('More Like This')" :description="__('Stories that share this one’s genres and themes. Open one to keep going.')" previews="quick-view-similar" class="mt-12 lg:mt-16">
            @foreach ($this->similar as $similar)
                <li wire:key="similar-{{ $similar->id }}" class="shrink-0">
                    <x-story-preview :media="$similar">
                        <x-cover-tile :media="$similar" />
                    </x-story-preview>
                </li>
            @endforeach
        </x-shelf>
    @endif

    @if ($this->attributions->isNotEmpty())
        <footer class="mx-gutter mt-12 border-t border-hairline pt-6 lg:mt-16">
            <h2 class="text-meta text-ink-subtle">{{ __('Data from') }}</h2>

            <ul class="mt-2 flex flex-col gap-1 text-legal text-ink-subtle">
                @foreach ($this->attributions as $attribution)
                    <li wire:key="attribution-{{ $attribution['source']->id }}">
                        {{ $attribution['source']->attribution }}
                        @if ($attribution['url'])
                            <a href="{{ $attribution['url'] }}" rel="noopener nofollow" class="focus-ring rounded-control text-link underline">{{ __('View on :source', ['source' => $attribution['source']->name]) }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </footer>
    @endif
</div>
