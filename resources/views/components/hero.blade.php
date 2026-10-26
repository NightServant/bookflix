@props([
    'slides' => null,
    'media' => null,
    'size' => 'L',
    'label' => null,
])

{{--
    The featured-story carousel: the current story's cover as the backdrop, its eyebrow, title, blurb and actions on the
    left, the next three stories as cards on the right (below the text on a phone). Pass `:slides` (Media list) or one `:media`.
    `size` is L|M|S (S has no cards). There is no autoplay. The `cta` slot replaces the two per-slide buttons.
    "Add to Library" dispatches a `library-add` event with the media id (the library arrives in Phase 5).
    Eager-load `mediaType` and `genres` on fetched media (the eyebrow reads them).
--}}
@php
    $items = collect($slides ?? [$media])->filter()->values();
    $count = $items->count();
    $total = str_pad((string) $count, 2, '0', STR_PAD_LEFT);
    $compact = strtoupper($size) === 'S';

    [$height, $titleSize] = match (strtoupper($size)) {
        'S' => ['min-h-56 lg:min-h-64', 'text-lead'],
        'M' => ['min-h-80 lg:min-h-96', 'text-section'],
        default => ['min-h-112 lg:min-h-144', 'text-section md:text-hero'],
    };

    $cards = $items->map(fn ($slide) => ['id' => $slide->id, 'title' => $slide->title, 'cover' => $slide->cover_url])->all();
    $control = 'focus-ring inline-flex size-11 items-center justify-center rounded-card bg-surface-input text-ink hover:bg-hairline';
@endphp

@if ($count > 0)
    <section
        aria-roledescription="carousel"
        aria-label="{{ $label ?? __('Featured stories') }}"
        x-data="hero(@js($cards))"
        @keydown.arrow-left="prev()"
        @keydown.arrow-right="next()"
        {{ $attributes->class('relative overflow-hidden rounded-card bg-surface-deep') }}
    >
        <div class="absolute inset-0" aria-hidden="true">
            @foreach ($items as $index => $slide)
                @if ($slide->cover_url)
                    <img
                        src="{{ $slide->cover_url }}"
                        alt=""
                        width="1280"
                        height="720"
                        decoding="async"
                        data-backdrop="{{ $index }}"
                        wire:key="hero-backdrop-{{ $slide->id }}"
                        x-show="current === {{ $index }}"
                        x-motion-crossfade="fade"
                        @if ($index > 0) style="display: none" @endif
                        @if ($index === 0) fetchpriority="high" @else loading="lazy" @endif
                        class="absolute inset-0 size-full object-cover"
                    >
                @endif
            @endforeach
            <div data-hero-scrim class="absolute inset-0 bg-linear-to-t from-canvas via-canvas/80 to-canvas/60 lg:bg-linear-to-r lg:via-canvas/60 lg:to-canvas/10"></div>
        </div>

        <div data-hero-content class="relative flex flex-col justify-end gap-8 p-4 sm:p-8 {{ $height }} lg:flex-row lg:items-center lg:justify-between">
            <div class="max-w-2xl" aria-live="polite">
                @foreach ($items as $index => $slide)
                    @php
                        $eyebrow = collect([$slide->mediaType->name, $slide->genres->first()?->name])->filter()->implode(' · ');
                        $words = preg_split('/\s+/u', trim($slide->title), -1, PREG_SPLIT_NO_EMPTY);
                    @endphp
                    <article
                        role="group"
                        aria-roledescription="slide"
                        aria-label="{{ __(':current of :total', ['current' => $index + 1, 'total' => $count]) }}"
                        data-slide="{{ $index }}"
                        wire:key="hero-slide-{{ $slide->id }}"
                        x-show="current === {{ $index }}"
                        @if ($index > 0) style="display: none" @endif
                    >
                        <p class="text-legal uppercase tracking-widest text-ink-subtle">{{ $eyebrow }}</p>
                        <h1 class="mt-1 {{ $titleSize }} text-ink">
                            @foreach ($words as $word)<span class="-mb-[0.12em] inline-block overflow-hidden pb-[0.12em] align-bottom"><span data-word class="inline-block">{{ $word }}</span></span> @endforeach
                        </h1>
                        @if ($slide->description)
                            <p class="mt-4 line-clamp-2 text-body text-ink-subtle">{{ $slide->description }}</p>
                        @endif
                        <div class="mt-6 flex flex-wrap gap-3">
                            @if (isset($cta) && $cta->isNotEmpty())
                                {{ $cta }}
                            @else
                                <x-cta :href="route('stories.show', $slide->slug)" wire:navigate>{{ __('Start reading') }}</x-cta>
                                <x-cta variant="secondary" @click="$dispatch('library-add', { id: {{ $slide->id }} })">{{ __('Add to Library') }}</x-cta>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>

            @if ($count > 1)
                <div class="flex min-w-0 flex-col gap-6 lg:items-end">
                    @unless ($compact)
                        <ul aria-label="{{ __('Up next') }}" class="-mx-1 flex snap-x gap-3 overflow-x-auto p-1 [scrollbar-width:none] lg:overflow-visible [&::-webkit-scrollbar]:hidden">
                            <template x-for="card in upcoming" :key="card.id">
                                <li :data-card="card.id" class="w-24 shrink-0 snap-start lg:w-32">
                                    <button
                                        type="button"
                                        @click="show(card.index)"
                                        :aria-label="@js(__('Show :title')).replace(':title', card.title)"
                                        class="group focus-ring block w-full rounded-card text-start"
                                    >
                                        <span class="block aspect-[2/3] overflow-hidden rounded-card bg-surface-card">
                                            <img
                                                x-show="card.cover"
                                                :src="card.cover"
                                                alt=""
                                                width="128"
                                                height="192"
                                                loading="lazy"
                                                class="size-full object-cover motion-safe:transition-transform motion-safe:duration-(--motion-fast) motion-safe:ease-out-bookflix motion-safe:group-hover:scale-105"
                                            >
                                        </span>
                                        <span class="mt-2 line-clamp-2 block text-legal text-ink" x-text="card.title"></span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    @endunless

                    <div class="flex items-center gap-4">
                        <div aria-hidden="true" class="h-0.5 w-16 bg-hairline sm:w-40">
                            <div
                                class="h-full origin-left scale-x-(--progress) bg-ink motion-safe:transition-transform motion-safe:duration-(--motion-base) motion-safe:ease-out-bookflix"
                                style="--progress: {{ round(1 / $count, 4) }}"
                                :style="{ '--progress': (current + 1) / count }"
                            ></div>
                        </div>
                        <p aria-hidden="true" class="tabular-nums"><span class="text-section text-ink" x-text="String(current + 1).padStart(2, '0')">01</span> <span class="text-meta text-ink-muted">/ {{ $total }}</span></p>
                        <button type="button" @click="prev()" aria-label="{{ __('Previous slide') }}" class="{{ $control }}">
                            <flux:icon name="chevron-left" class="size-5" />
                        </button>
                        <button type="button" @click="next()" data-hero-next aria-label="{{ __('Next slide') }}" class="{{ $control }}">
                            <flux:icon name="chevron-right" class="size-5" />
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </section>
@endif
