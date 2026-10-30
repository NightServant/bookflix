@props([
    'media',
    'size' => 'M',
    'progress' => null,
    'reason' => null,
    'badge' => false,
    'fluid' => false,
    'selectable' => false,
])

{{--
    A 2:3 cover linking to the story. `size` S|M|L sets the width (`fluid` fills the cell instead, for grids);
    `badge` adds the media type (eager-load `mediaType` first); `progress` is a 0-100 percentage; `reason` is a short "why this" line.
    `selectable` turns the tile into something to pick instead of open: a label around a native checkbox (bind it with `wire:model` and a
    `value`, which go to the checkbox like `name` and `x-model`), with a ring and a check on the cover once ticked and the focus ring
    drawn on the cover. It links nowhere. Used where a reader chooses stories (onboarding).
--}}
@php
    [$width, $widthClasses] = match (strtoupper($size)) {
        'S' => [112, 'w-28'],
        'L' => [224, 'w-44 sm:w-56'],
        default => [160, 'w-36 sm:w-40'],
    };
    $height = $width * 3 / 2;
    $progress = $progress === null ? null : max(0, min(100, (int) $progress));
    $inputKeys = ['wire:model', 'x-model', 'value', 'name', 'checked'];
@endphp

<{{ $selectable ? 'label' : 'a' }}
    @unless ($selectable)
        href="{{ route('stories.show', $media->slug) }}"
        wire:navigate
    @endunless
    {{ $attributes->whereDoesntStartWith($selectable ? $inputKeys : [])->class(['group block shrink-0 rounded-card', 'focus-ring' => ! $selectable, 'cursor-pointer' => $selectable, $fluid ? 'w-full' : $widthClasses]) }}
>
    @if ($selectable)
        <input type="checkbox" class="peer sr-only" {{ $attributes->whereStartsWith($inputKeys) }}>
    @endif

    <span @class([
        'relative block aspect-[2/3] overflow-hidden rounded-card bg-surface-card',
        'ring-offset-2 ring-offset-canvas peer-checked:ring-2 peer-checked:ring-ink peer-focus-visible:ring-2 peer-focus-visible:ring-accent-electric peer-checked:peer-focus-visible:ring-accent-electric' => $selectable,
    ])>
        @if ($media->cover_url)
            <img
                src="{{ $media->cover_url }}"
                alt=""
                width="{{ $width }}"
                height="{{ $height }}"
                loading="lazy"
                decoding="async"
                class="size-full object-cover motion-safe:transition-transform motion-safe:duration-(--motion-fast) motion-safe:ease-out-bookflix motion-safe:group-hover:scale-105"
            >
        @else
            <x-cover-fallback :title="$media->title" />
        @endif

        @if ($badge)
            <span class="absolute start-2 top-2 rounded-control bg-canvas/80 px-2 py-1 text-legal text-ink">{{ $media->mediaType->name }}</span>
        @endif

        @if ($selectable)
            <span aria-hidden="true" class="absolute end-2 top-2 hidden size-7 items-center justify-center rounded-control bg-ink text-canvas group-has-[:checked]:flex">
                <flux:icon name="check" variant="micro" class="size-5" />
            </span>
        @endif

        @if ($progress !== null)
            <span
                role="progressbar"
                aria-label="{{ __('Progress') }}"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="{{ $progress }}"
                class="absolute inset-x-0 bottom-0 block h-1 bg-surface-input"
            >
                <span class="block h-full bg-ink" style="width: {{ $progress }}%"></span>
            </span>
        @endif
    </span>

    <span class="mt-2 line-clamp-2 min-h-[2lh] text-meta text-ink">{{ $media->title }}</span>

    @if ($reason)
        <span class="mt-1 line-clamp-2 text-legal text-ink-subtle">{{ $reason }}</span>
    @endif
</{{ $selectable ? 'label' : 'a' }}>
