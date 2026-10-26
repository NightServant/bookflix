@props([
    'title',
    'href' => null,
    'eyebrow' => null,
    'featured' => false,
    'id' => null,
])

{{--
    A titled, horizontally scrolling row. The slot holds `<li>` items (usually `<x-cover-tile>`, each with a `wire:key`).
    `featured` is the gradient shelf and is reserved for Trending Now; every other shelf is plain.
    Titles are Title Case. `href` adds "See all" (named after the shelf for screen readers).
--}}
@php
    $headingId = $id ?? 'shelf-'.Str::slug($title);
    $arrow = 'focus-ring inline-flex size-11 items-center justify-center rounded-card bg-surface-input text-ink hover:bg-hairline disabled:cursor-default disabled:opacity-40 disabled:hover:bg-surface-input';
@endphp

<section
    aria-labelledby="{{ $headingId }}"
    x-data="shelf"
    x-init="$nextTick(() => update())"
    @resize.window.debounce.150ms="update()"
    {{ $attributes->class([
        'rounded-card p-4 sm:p-6',
        'bg-linear-to-r from-featured-from via-featured-via to-featured-to' => $featured,
        'bg-surface-deep' => ! $featured,
    ]) }}
>
    <div class="flex items-end justify-between gap-4">
        <div class="min-w-0">
            @if ($eyebrow)
                <p class="text-legal uppercase tracking-widest text-ink-subtle">{{ $eyebrow }}</p>
            @endif
            <h2 id="{{ $headingId }}" class="text-lead text-ink">{{ $title }}</h2>
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

            <div x-show="canScroll" style="display: none" class="flex items-center gap-2">
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
        class="-mx-1 mt-3 flex snap-x snap-mandatory scroll-px-1 gap-4 overflow-x-auto p-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden [&>li]:shrink-0 [&>li]:snap-start"
    >
        {{ $slot }}
    </ul>
</section>
