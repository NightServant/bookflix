@props(['icon' => 'inbox', 'title', 'description' => null])

{{-- What a list says when it is empty: an outline icon, what is missing, and why that is fine (or what to do). --}}
<div {{ $attributes->class('flex flex-col items-center px-6 py-12 text-center') }}>
    <span class="flex size-12 items-center justify-center rounded-card bg-surface-input text-ink-subtle">
        <flux:icon :name="$icon" variant="outline" class="size-6" />
    </span>
    <p class="mt-4 text-lead text-ink">{{ $title }}</p>
    @if ($description)
        <p class="mt-1 max-w-sm text-meta text-ink-subtle text-pretty">{{ $description }}</p>
    @endif
</div>
