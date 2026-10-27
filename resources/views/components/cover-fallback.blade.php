@props(['title', 'compact' => false])

{{--
    The stand-in for a missing cover: the title set in the display face on a `surface-card` book with a darker spine, so a shelf
    of covers never has a hole or an icon in a box. It fills its 2:3 parent (the parent keeps the aspect ratio and the rounding)
    and scales its type to the cover's own width with container units. `compact` (list thumbnails too small for a title) sets
    only the first letter. The cover is decorative: the story's title is always written out next to it.
--}}
<span data-cover-fallback aria-hidden="true" {{ $attributes->class('relative block size-full overflow-hidden bg-surface-card [container-type:inline-size]') }}>
    <span class="absolute inset-y-0 start-0 w-[7cqw] bg-canvas/40"></span>

    @if ($compact)
        <span class="flex size-full items-center justify-center ps-[7cqw] text-[length:62cqw] font-black leading-none text-ink-muted">{{ mb_strtoupper(mb_substr(trim($title), 0, 1)) }}</span>
    @else
        <span class="flex size-full flex-col justify-between gap-[6cqw] p-[10cqw] ps-[17cqw]">
            <span class="line-clamp-6 hyphens-auto break-words text-[length:max(0.75rem,10.5cqw)] font-black leading-[1.05] tracking-tight text-ink">{{ $title }}</span>
            <span class="block h-px w-[24cqw] shrink-0 bg-ink-muted"></span>
        </span>
    @endif
</span>
