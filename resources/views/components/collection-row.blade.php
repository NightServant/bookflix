@props(['collection', 'preview', 'editorial' => false])

{{--
    One collection as a row of a list: a stack of its first covers, the name, and a line of facts (how many stories the reader may see,
    who may see it, when it last changed). The whole row is the link, 44px tall at the least. `preview` is what CollectionPreviews gives:
    `count` and `covers`. `editorial` links to the staff pick itself (a reader's own collection of the same name cannot take its place) and
    swaps the visibility for "Staff pick". Give each row a `wire:key` in the loop.
--}}
@php
    $href = route('collections.show', $editorial ? ['slug' => $collection->slug, 'editors' => 1] : $collection->slug);
    $public = $collection->visibility === \App\Enums\Visibility::Public;
@endphp

<a href="{{ $href }}" wire:navigate {{ $attributes->class('group focus-ring flex min-h-11 items-center gap-4 rounded-control py-4 sm:gap-6') }}>
    <span aria-hidden="true" class="flex w-[9.5rem] shrink-0 -space-x-6 ps-1 sm:w-44 sm:-space-x-7">
        @forelse ($preview['covers'] as $cover)
            <span class="relative block aspect-[2/3] w-12 shrink-0 overflow-hidden rounded-control bg-surface-card ring-2 ring-canvas motion-safe:transition-transform motion-safe:duration-(--motion-fast) motion-safe:ease-out-bookflix motion-safe:group-hover:-translate-y-0.5 sm:w-16" style="z-index: {{ 4 - $loop->index }}">
                <img src="{{ $cover }}" alt="" width="64" height="96" loading="lazy" decoding="async" class="size-full object-cover">
            </span>
        @empty
            <span class="relative flex aspect-[2/3] w-12 shrink-0 items-center justify-center overflow-hidden rounded-control bg-surface-card ring-2 ring-canvas sm:w-16">
                <x-cover-fallback :title="$collection->name" compact />
            </span>
        @endforelse
    </span>

    <span class="min-w-0 flex-1">
        <span class="block text-lead text-ink group-hover:underline">{{ $collection->name }}</span>

        <span class="mt-1 flex flex-wrap items-center gap-x-2 text-meta text-ink-subtle">
            @if ($preview['count'] > 0)
                <span class="tabular-nums">{{ trans_choice(':count story|:count stories', $preview['count']) }}</span>
            @else
                <span>{{ __('No stories yet') }}</span>
            @endif
            <span aria-hidden="true">·</span>
            @if ($editorial)
                <span class="inline-flex items-center gap-1"><flux:icon name="sparkles" variant="micro" class="size-4" />{{ __('Staff pick') }}</span>
            @else
                <span class="inline-flex items-center gap-1"><flux:icon :name="$public ? 'globe-alt' : 'lock-closed'" variant="micro" class="size-4" />{{ $public ? __('Public') : __('Private') }}</span>
            @endif
            <span aria-hidden="true">·</span>
            <span>{{ __('Updated') }} <time datetime="{{ $collection->updated_at->toIso8601String() }}" title="{{ $collection->updated_at->toFormattedDateString() }}">{{ $collection->updated_at->diffForHumans() }}</time></span>
        </span>

        @if (filled($collection->description))
            <span class="mt-1 line-clamp-1 block text-body text-ink-subtle">{{ $collection->description }}</span>
        @endif
    </span>

    <flux:icon name="chevron-right" variant="micro" class="size-5 shrink-0 text-ink-muted group-hover:text-ink" />
</a>
