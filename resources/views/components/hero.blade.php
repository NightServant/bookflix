@props([
    'slides' => null,
    'media' => null,
    'size' => 'L',
    'autoplay' => false,
    'eyebrow' => null,
    'label' => null,
    'interval' => 6000,
])

{{--
    The featured-story carousel. Pass `:slides` (a list of Media) or a single `:media`; `size` is L|M|S.
    Rotation is off unless `autoplay`, and is never started under reduced motion. The `cta` slot replaces the per-slide
    "View story" button. Eager-load `mediaType` and `publicPrimaryDewey.deweyClass` on fetched media (the info line reads them).
--}}
@php
    $items = collect($slides ?? [$media])->filter()->values();
    $count = $items->count();
    $total = str_pad((string) $count, 2, '0', STR_PAD_LEFT);

    [$height, $titleSize] = match (strtoupper($size)) {
        'S' => ['min-h-56 lg:min-h-64', 'text-lead'],
        'M' => ['min-h-80 lg:min-h-96', 'text-section'],
        default => ['min-h-112 lg:min-h-144', 'text-section md:text-hero'],
    };

    $control = 'focus-ring inline-flex size-11 items-center justify-center rounded-card bg-surface-input text-ink hover:bg-hairline';
@endphp

@if ($count > 0)
    <section
        aria-roledescription="carousel"
        aria-label="{{ $label ?? __('Featured stories') }}"
        x-data="hero({{ $count }}, @js((bool) $autoplay), {{ (int) $interval }})"
        @keydown.arrow-left="prev()"
        @keydown.arrow-right="next()"
        @mouseenter="hold = true"
        @mouseleave="hold = false"
        @focusin="hold = true"
        @focusout="hold = false"
        {{ $attributes->class('relative overflow-hidden rounded-card bg-surface-deep') }}
    >
        @if ($count > 1)
            {{-- Rotation control first in the tab order, as the APG carousel pattern asks. --}}
            <div class="absolute bottom-4 end-4 z-10 flex items-center gap-2">
                <p aria-hidden="true" class="me-2 text-meta tabular-nums text-ink"><span x-text="String(current + 1).padStart(2, '0')">01</span> / {{ $total }}</p>

                <button
                    type="button"
                    @click="toggle()"
                    aria-label="{{ $autoplay ? __('Pause slideshow') : __('Play slideshow') }}"
                    :aria-label="playing ? @js(__('Pause slideshow')) : @js(__('Play slideshow'))"
                    class="{{ $control }}"
                >
                    <span x-show="playing" @if (! $autoplay) style="display: none" @endif><flux:icon name="pause" class="size-5" /></span>
                    <span x-show="! playing" @if ($autoplay) style="display: none" @endif><flux:icon name="play" class="size-5" /></span>
                </button>
                <button type="button" @click="prev()" aria-label="{{ __('Previous slide') }}" class="{{ $control }}">
                    <flux:icon name="chevron-left" class="size-5" />
                </button>
                <button type="button" @click="next()" aria-label="{{ __('Next slide') }}" class="{{ $control }}">
                    <flux:icon name="chevron-right" class="size-5" />
                </button>
            </div>
        @endif

        <div aria-live="polite" :aria-live="playing ? 'off' : 'polite'">
            @foreach ($items as $index => $slide)
                <article
                    role="group"
                    aria-roledescription="slide"
                    aria-label="{{ __(':current of :total', ['current' => $index + 1, 'total' => $count]) }}"
                    wire:key="hero-{{ $slide->id }}"
                    x-show="current === {{ $index }}"
                    x-motion-crossfade="current"
                    @if ($index > 0) style="display: none" @endif
                    class="relative flex flex-col justify-end {{ $height }}"
                >
                    @if ($slide->cover_url)
                        <img
                            src="{{ $slide->cover_url }}"
                            alt=""
                            width="1280"
                            height="720"
                            decoding="async"
                            @if ($index === 0) fetchpriority="high" @else loading="lazy" @endif
                            class="absolute inset-0 size-full object-cover"
                        >
                    @endif
                    <div class="absolute inset-0 bg-linear-to-t from-canvas via-canvas/70 to-canvas/20" aria-hidden="true"></div>

                    <div class="relative max-w-2xl p-4 pb-20 sm:p-8 sm:pb-8">
                        @if ($eyebrow)
                            <p class="text-legal uppercase tracking-widest text-ink-subtle">{{ $eyebrow }}</p>
                        @endif
                        <h1 class="mt-1 {{ $titleSize }} text-ink">{{ $slide->title }}</h1>
                        <x-story-info :media="$slide" class="mt-3" />
                        @if ($slide->description)
                            <p class="mt-3 line-clamp-3 text-body text-ink-subtle">{{ $slide->description }}</p>
                        @endif
                        <div class="mt-6">
                            @if (isset($cta) && $cta->isNotEmpty())
                                {{ $cta }}
                            @else
                                <x-cta :href="route('stories.show', $slide->slug)" wire:navigate>{{ __('View story') }}</x-cta>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </section>
@endif
