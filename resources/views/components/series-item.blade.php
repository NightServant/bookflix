@props([
    'media',
    'position' => null,
    'label' => null,
])

{{--
    One volume in a series list: a bracketed index in tabular figures, a small 2:3 cover, the linked title and the year.
    `position` is `media_series.position` (decimal(8,2), so 1, "1.00" and "1.50" all work): `[01]`, `[01.5]`. Without a position
    the pivot `label` stands in (`[Omnibus]`), and with neither there is no bracket. The cover also links to the story, but only
    for pointers; it is out of the tab order and hidden from screen readers, so the title is the one link.
    The list around it is the page's: `<ul>` with an `<li wire:key>` per volume, ordered by `Series::orderedMedia()`.
--}}
@php
    $index = null;

    if ($position !== null && $position !== '') {
        $whole = rtrim(rtrim(number_format((float) $position, 2, '.', ''), '0'), '.');
        $index = '['.preg_replace_callback('/^\d+/', fn (array $digits) => str_pad($digits[0], 2, '0', STR_PAD_LEFT), $whole).']';
    } elseif (filled($label)) {
        $index = '['.$label.']';
    }

    $story = route('stories.show', $media->slug);
@endphp

<div {{ $attributes->class('flex items-center gap-4 py-2') }}>
    @if ($index !== null)
        <span data-index class="min-w-16 shrink-0 text-meta tabular-nums text-ink-subtle">{{ $index }}</span>
    @endif

    <a href="{{ $story }}" wire:navigate aria-hidden="true" tabindex="-1" class="block aspect-[2/3] w-12 shrink-0 overflow-hidden rounded-control bg-surface-card">
        @if ($media->cover_url)
            <img src="{{ $media->cover_url }}" alt="" width="48" height="72" loading="lazy" decoding="async" class="size-full object-cover">
        @else
            <span class="flex size-full items-center justify-center">
                <flux:icon name="book-open" class="size-5 text-ink-muted" />
            </span>
        @endif
    </a>

    <a href="{{ $story }}" wire:navigate class="focus-ring inline-flex min-h-11 min-w-0 items-center rounded-control text-body text-ink hover:underline">{{ $media->title }}</a>

    @if ($media->publication_year)
        <span data-year class="ms-auto shrink-0 text-meta tabular-nums text-ink-subtle">{{ $media->publication_year }}</span>
    @endif
</div>
