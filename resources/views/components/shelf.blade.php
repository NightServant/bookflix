@props([
    'title',
    'href' => null,
    'eyebrow' => null,
    'featured' => false,
    'id' => null,
    'bleed' => false,
])

{{--
    A titled, horizontally scrolling row. The slot holds `<li>` items (usually `<x-cover-tile>`, each with a `wire:key`).
    `featured` is the gradient shelf and is reserved for Trending Now; every other shelf is plain.
    Titles are Title Case. `href` adds "See all" (named after the shelf for screen readers).
    The track dissolves at both of its edges, always (the `edge-fade` mask): a bleed shelf fades over a strip at each screen edge that is
    wider than the gutter (3rem to 16rem, 18vw between), so the outer part of the first tile and the tiles running off the right
    melt away; a boxed shelf fades only on the right.
    The arrows sit outside the track and stay opaque.
    `bleed` makes it a band that spans the whole page (in a `flush` layout): the title, controls and the first tile line up with the
    header's gutter while the track runs on past the right edge. Without it the shelf is a rounded card inside the page's container.
--}}
@php
    $headingId = $id ?? 'shelf-'.Str::slug($title);
    // Ghost buttons like the hero's: a bright icon in a 44px box, no fill; the dimmer one is disabled.
    $arrow = 'focus-ring inline-flex size-11 items-center justify-center rounded-control text-ink hover:ring-1 hover:ring-ink/50 disabled:cursor-default disabled:text-ink-muted disabled:hover:ring-0';
@endphp

<section
    aria-labelledby="{{ $headingId }}"
    x-data="shelf"
    x-init="$nextTick(() => update())"
    @resize.window.debounce.150ms="update()"
    {{ $attributes->class([
        'rounded-card p-4 sm:p-6' => ! $bleed,
        'py-8 lg:py-12' => $bleed,
        'bg-linear-to-r from-featured-from via-featured-via to-featured-to' => $featured,
        'bg-surface-deep' => ! $featured,
    ]) }}
>
    <div @class(['flex items-end justify-between gap-4', 'px-gutter' => $bleed])>
        <div class="min-w-0">
            @if ($eyebrow)
                <p class="text-legal uppercase tracking-widest text-ink-subtle">{{ $eyebrow }}</p>
            @endif
            <h2 id="{{ $headingId }}" class="{{ $bleed ? 'text-section' : 'text-lead' }} text-ink">{{ $title }}</h2>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            @if ($href)
                <a
                    href="{{ $href }}"
                    wire:navigate
                    aria-label="{{ __('See all in :shelf', ['shelf' => $title]) }}"
                    class="focus-ring inline-flex min-h-11 items-center rounded-control px-2 text-meta {{ $featured ? 'text-ink underline' : 'text-link hover:underline' }}"
                >{{ __('See all') }}</a>
            @endif

            <div x-show="canScroll" style="display: none" class="items-center max-sm:!hidden sm:flex">
                <button type="button" x-ref="prev" @click="go(-1)" :disabled="atStart" aria-label="{{ __('Previous in :shelf', ['shelf' => $title]) }}" class="{{ $arrow }}">
                    <flux:icon name="chevron-left" class="size-5" />
                </button>
                <button type="button" x-ref="next" @click="go(1)" :disabled="atEnd" aria-label="{{ __('Next in :shelf', ['shelf' => $title]) }}" class="{{ $arrow }}">
                    <flux:icon name="chevron-right" class="size-5" />
                </button>
            </div>
        </div>
    </div>

    <ul
        x-ref="track"
        x-motion-reveal
        data-stagger="30"
        @scroll.passive="update()"
        @class([
            'edge-fade mt-3 flex snap-x snap-mandatory gap-4 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden [&>li]:shrink-0 [&>li]:snap-start',
            '[--fade-start:0px] [--fade-end:2rem] -mx-1 scroll-px-1 p-1' => ! $bleed,
            '[--fade-start:clamp(3rem,18vw,16rem)] [--fade-end:clamp(3rem,18vw,16rem)] scroll-px-gutter px-gutter py-1' => $bleed,
        ])
    >
        {{ $slot }}
    </ul>
</section>
