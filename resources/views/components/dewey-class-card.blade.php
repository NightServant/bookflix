@props(['deweyClass'])

<a
    href="{{ route('explore.show', $deweyClass->code) }}"
    wire:navigate
    {{ $attributes->class('focus-ring flex h-full min-h-11 flex-col gap-1 rounded-card bg-surface-card p-4 hover:bg-surface-input') }}
>
    <span class="text-meta text-ink-subtle">{{ $deweyClass->code }}</span>
    <span class="text-lead text-ink">{{ $deweyClass->name }}</span>
    <span class="text-meta text-ink-subtle">{{ trans_choice(':count story|:count stories', app(\App\Classification\DeweyCounts::class)->forClass($deweyClass)) }}</span>
</a>
