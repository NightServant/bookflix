@props([
    'deweyClass',
    'fluid' => false,
])

{{--
    A main class of the Dewey-inspired shelving as a compact typographic tile: the bracketed class code, the name, the story count
    in tabular figures. It links to the class's page. `fluid` fills its grid cell, otherwise it is a fixed-width shelf item (its height
    never changes, so a skeleton of the same size can stand in). The count is the `stories_count` attribute: use `DeweyCounts::rootsWithCounts()`.
--}}
<a
    href="{{ route('explore.show', $deweyClass->code) }}"
    wire:navigate
    {{ $attributes->class(['group focus-ring flex h-24 flex-col justify-between rounded-card bg-surface-card p-3 hover:bg-surface-input sm:h-28', $fluid ? 'w-full' : 'w-52 shrink-0']) }}
>
    <span class="text-legal tabular-nums text-ink-subtle">[{{ $deweyClass->code }}]</span>
    <span class="block">
        <span class="line-clamp-2 text-body font-medium leading-snug text-ink">{{ $deweyClass->name }}</span>
        <span class="block text-legal tabular-nums text-ink-subtle">{{ trans_choice(':count story|:count stories', $deweyClass->stories_count) }}</span>
    </span>
</a>
