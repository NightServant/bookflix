@props([
    'title',
    'href' => null,
    'eyebrow' => null,
    'featured' => false,
    'id' => null,
    'bleed' => false,
    'previews' => null,
])

{{--
    A titled, horizontally scrolling row. The slot holds `<li>` items (usually `<x-cover-tile>`, each with a `wire:key`).
    `featured` is the gradient shelf and is reserved for Trending Now; every other shelf is plain.
    Titles are Title Case. `href` adds "See all" (named after the shelf for screen readers).
    The track dissolves at an edge that has more to scroll to (the `edge-fade` mask, driven by `x-edge-fade`): the left only once it has
    been scrolled, the right until the end, 2rem wide in a card and 3 to 4rem on a bleed shelf.
    On a bleed shelf only the band is full width: the track keeps the header's gutters as its margins and clips there, so no cover
    ever shows in the side margins, and the first tile sits on the logo's edge. The track reaches 0.25rem past each gutter and pads that
    much back in (with a matching `scroll-padding`), so the 4px focus ring of an outermost tile is inside the clip box while the tiles stay on the gutter.
    `previews` (a name, unique per page) wraps the shelf in <x-story-previews> for its <x-story-preview> tiles, which then share one preview card and one quick view dialog.
    The card sits outside the track, so the mask (which makes the track a stacking context at `z-10`) never clips it and it lands over the shelf beneath.
    The arrows sit outside the track and stay opaque.
    `bleed` makes it a band that spans the whole page (in a `flush` layout): the title, controls and the first tile line up with the
    header's gutter while the track runs on past the right edge. Without it the shelf is a rounded card inside the page's container.
--}}
@php
    $headingId = $id ?? 'shelf-'.Str::slug($title);
    // Ghost buttons like the hero's: a bright icon in a 44px box, no fill; the dimmer one is disabled.
    $arrow = 'focus-ring inline-flex size-11 items-center justify-center rounded-control text-ink hover:ring-1 hover:ring-ink/50 disabled:cursor-default disabled:text-ink-muted disabled:hover:ring-0';
@endphp

<x-story-previews :name="$previews">
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
                        class="focus-ring inline-flex min-h-11 items-center gap-1 rounded-control px-2 text-meta {{ $featured ? 'text-ink underline' : 'text-link hover:underline' }}"
                    >{{ __('See all') }}<flux:icon name="arrow-right" variant="micro" class="size-4" /></a>
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
            x-edge-fade
            data-stagger="30"
            @scroll.passive="update()"
            @class([
                'edge-fade relative z-10 mt-3 flex snap-x snap-mandatory gap-4 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden [&>li]:shrink-0 [&>li]:snap-start',
                '[--fade-width:2rem] -mx-1 scroll-px-1 p-1' => ! $bleed,
                '[--fade-width:clamp(3rem,4vw,4rem)] mx-[calc(var(--spacing-gutter)_-_0.25rem)] scroll-px-1 p-1' => $bleed,
            ])
        >
            {{ $slot }}
        </ul>
    </section>
</x-story-previews>
