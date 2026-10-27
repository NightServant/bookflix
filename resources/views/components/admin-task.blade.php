@props(['href', 'icon', 'title', 'description', 'stat' => null, 'attention' => false])

{{--
    One task on the admin dashboard: icon, title, a one-line description and, when it is cheap to know, a live figure.
    `stat` is the figure's text; `attention` fills it (work waiting) instead of leaving it a quiet outline.
--}}
<a href="{{ $href }}" wire:navigate {{ $attributes->class('group focus-ring flex min-h-40 flex-col rounded-card bg-surface-card p-5 hover:bg-surface-input') }}>
    <span class="flex items-start justify-between gap-3">
        <span class="flex size-11 items-center justify-center rounded-card bg-surface-deep text-ink">
            <flux:icon :name="$icon" variant="outline" class="size-6" />
        </span>
        @if ($stat)
            <span @class([
                'rounded-full px-3 py-1 text-legal tabular-nums',
                'bg-ink text-canvas font-medium' => $attention,
                'border border-hairline text-ink-subtle' => ! $attention,
            ])>{{ $stat }}</span>
        @endif
    </span>

    <span class="mt-4 flex items-center gap-2 text-lead text-ink">
        {{ $title }}
        <flux:icon name="arrow-right" variant="outline" class="size-4 -translate-x-1 text-ink-subtle opacity-0 transition group-hover:translate-x-0 group-hover:opacity-100 group-focus-visible:translate-x-0 group-focus-visible:opacity-100 motion-reduce:transition-none" />
    </span>
    <span class="mt-1 text-meta text-ink-subtle text-pretty">{{ $description }}</span>
</a>
