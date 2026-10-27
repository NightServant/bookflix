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
        return $this->view()->title($this->media->title);
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
    $chip = 'focus-ring inline-flex min-h-11 items-center rounded-full bg-surface-input px-4 text-meta text-ink hover:bg-hairline';
@endphp

<section class="py-12" x-data>
    @if ($this->primaryClass)
        <x-dewey-breadcrumb :dewey-class="$this->primaryClass" />
    @endif

    <div class="mt-6 grid gap-8 md:grid-cols-[14rem_minmax(0,1fr)] lg:grid-cols-[16rem_minmax(0,1fr)]">
        <div class="w-48 md:w-full">
            <span class="block aspect-[2/3] overflow-hidden rounded-card bg-surface-deep">
                @if ($media->cover_url)
                    <img src="{{ $media->cover_url }}" alt="{{ __('Cover of :title', ['title' => $media->title]) }}" width="256" height="384" decoding="async" class="size-full object-cover">
                @else
                    <x-cover-fallback :title="$media->title" />
                @endif
            </span>
        </div>

        <div class="min-w-0">
            <h1 class="text-section text-ink">{{ $media->title }}</h1>

            @if ($media->alternative_titles !== [])
                <p class="mt-1 text-meta text-ink-subtle">{{ __('Also known as') }} {{ implode(', ', $media->alternative_titles) }}</p>
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

            <x-story-info :media="$media" class="mt-3" />

            <x-rating-display :average="$media->average_rating" :count="$media->ratings_count" class="mt-3" />

            @if (Route::has('library.store'))
                <div class="mt-6">
                    <x-cta icon="plus" @click="$dispatch('library-add', { id: {{ $media->id }} })">{{ __('Add to Library') }}</x-cta>
                </div>
            @endif

            @if ($neighbours)
                <p class="mt-6 text-meta text-ink-subtle">
                    {{ __('Part of') }}
                    @if ($linkSeries)
                        <a href="{{ route('series.show', $neighbours['series']->slug) }}" wire:navigate class="focus-ring rounded-control text-link hover:underline">{{ $neighbours['series']->title }}</a>
                    @else
                        <span class="text-ink">{{ $neighbours['series']->title }}</span>
                    @endif
                </p>

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

    <x-tabs :tabs="$tabs" :label="__('Story sections')" class="mt-10">
        <x-slot:overview>
            <div class="flex max-w-prose flex-col gap-8">
                @if (filled($media->description))
                    <section>
                        <h2 class="text-lead text-ink">{{ __('Description') }}</h2>
                        <p class="mt-2 text-body text-ink-subtle">{{ $media->description }}</p>
                    </section>
                @endif

                @if ($this->classes->isNotEmpty())
                    <section>
                        <h2 class="text-lead text-ink">{{ __('Classification') }}</h2>
                        <ul class="mt-2 flex flex-col">
                            @foreach ($this->classes as $class)
                                <li wire:key="class-{{ $class->id }}">
                                    <a href="{{ route('explore.show', $class->deweyClass->code) }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center gap-2 rounded-control text-body text-link hover:underline">
                                        <span class="tabular-nums">{{ $class->deweyClass->code }}</span>
                                        <span>{{ $class->deweyClass->name }}</span>
                                        @if ($class->classification_type === App\Enums\ClassificationType::Secondary)
                                            <span class="text-legal text-ink-subtle">{{ __('Also') }}</span>
                                        @endif
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
                                        <a href="{{ $link($item) }}" wire:navigate class="{{ $chip }}">{{ $item->name }}</a>
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

                            <ul class="mt-2">
                                @foreach ($series->orderedMedia as $volume)
                                    <li wire:key="volume-{{ $series->id }}-{{ $volume->id }}">
                                        <x-series-item :media="$volume" :position="$volume->pivot->position" :label="$volume->pivot->label" :aria-current="$volume->is($media) ? 'true' : null" />
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endforeach
                </div>
            </x-slot:series>
        @endif

        @if ($media->relations->isNotEmpty())
            <x-slot:related>
                <div class="max-w-prose">
                    <h2 class="text-lead text-ink">{{ __('Related stories') }}</h2>

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

    @if ($this->similar->isNotEmpty())
        <x-shelf :title="__('More Like This')" previews="quick-view-similar" class="mt-12">
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
        <footer class="mt-12 border-t border-hairline pt-6">
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
</section>
