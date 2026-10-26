@props([
    'deweyClass',
    'fluid' => false,
])

{{--
    A main class of the Dewey-inspired shelving as a typographic tile: the bracketed class code, the name set large, the story count
    in tabular figures. It links to the class's page. `fluid` fills its grid cell, otherwise it is a fixed-width shelf item (its height
    never changes, so a skeleton of the same size can stand in). The count is the `stories_count` attribute: use `DeweyCounts::rootsWithCounts()`.
--}}
<a
    href="{{ route('explore.show', $deweyClass->code) }}"
    wire:navigate
    {{ $attributes->class(['group focus-ring flex h-40 flex-col justify-between rounded-card bg-surface-card p-4 hover:bg-surface-input', $fluid ? 'w-full' : 'w-56 shrink-0 sm:w-64']) }}
>
    <span class="text-meta tabular-nums text-ink-subtle">[{{ $deweyClass->code }}]</span>
    <span class="block">
        <span class="line-clamp-2 text-lead tracking-tight text-ink">{{ $deweyClass->name }}</span>
        <span class="mt-1 block text-meta tabular-nums text-ink-subtle">{{ trans_choice(':count story|:count stories', $deweyClass->stories_count) }}</span>
    </span>
</a>
