@props([
    'media',
    'size' => 'M',
    'progress' => null,
    'reason' => null,
    'reasonSubject' => null,
    'recommendation' => null,
    'dismissible' => false,
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
    `reason` is the line under the title: 13px, up to three lines, muted. `reasonSubject` is the title or genre the reason names, white and Medium after it
    ("Because you read" **Dracula**); nothing else marks it, so it reads as a caption and never competes with the cover.
    `recommendation` (the id of the `recommendations` row) makes the tile report a click and a view (see `recommendationTiles`, which the page puts around the shelves).
    `dismissible` adds the 44px overflow button (needs `recommendation`): hidden until the tile is hovered or has focus, always shown on a phone or a touch screen, where it
    opens a bottom sheet instead of the one-item menu (Escape is handled by the page's `recommendationTiles` in the capture phase, ahead of the preview card's own Escape). It sits beside the link, not in it (a button cannot be inside a link), and so does the menu.
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
    $menu = $dismissible && ! $selectable && $recommendation !== null;
@endphp

@if ($menu)
    <div class="group/tile relative {{ $fluid ? 'w-full' : $widthClasses }}">
@endif

<{{ $selectable ? 'label' : 'a' }}
    @unless ($selectable)
        href="{{ route('stories.show', $media->slug) }}"
        wire:navigate
    @endunless
    @if ($recommendation !== null && ! $selectable)
        data-recommendation="{{ $recommendation }}"
    @endif
    {{ $attributes->whereDoesntStartWith($selectable ? $inputKeys : [])->class(['group block shrink-0 rounded-card', 'focus-ring' => ! $selectable, 'cursor-pointer' => $selectable, $menu ? 'w-full' : ($fluid ? 'w-full' : $widthClasses)]) }}
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
        <span class="mt-1 line-clamp-3 text-legal text-ink-subtle">{{ $reason }}@if ($reasonSubject) <span class="font-medium text-ink">{{ $reasonSubject }}</span>@endif</span>
    @endif
</{{ $selectable ? 'label' : 'a' }}>

@if ($menu)
        <button
            type="button"
            data-tile-menu="{{ $recommendation }}"
            data-title="{{ $media->title }}"
            aria-haspopup="menu"
            :aria-haspopup="popup"
            aria-expanded="false"
            :aria-expanded="menuId === {{ $recommendation }} ? 'true' : 'false'"
            @click="openMenu($el)"
            aria-label="{{ __('Not interested in :title', ['title' => $media->title]) }}"
            class="focus-ring absolute end-2 top-2 z-10 inline-flex size-11 items-center justify-center rounded-control bg-canvas/90 text-ink opacity-0 group-focus-within/tile:opacity-100 group-hover/tile:opacity-100 max-sm:opacity-100 pointer-coarse:opacity-100 motion-safe:transition-opacity motion-safe:duration-(--motion-fast)"
        >
            <flux:icon name="ellipsis-horizontal" class="size-5" />
        </button>

        <div
            role="menu"
            aria-label="{{ __('Options for :title', ['title' => $media->title]) }}"
            x-show="menuId === {{ $recommendation }}"
            style="display: none"
            @keydown.tab="closeMenu()"
            @keydown.arrow-down.prevent
            @keydown.arrow-up.prevent
            class="absolute inset-x-1 top-14 z-20 rounded-control border border-hairline bg-surface-card p-1 shadow-overlay"
        >
            <button
                type="button"
                role="menuitem"
                @click="notInterested()"
                class="focus-ring flex min-h-11 w-full items-center gap-2 rounded-control px-2 text-start text-meta text-ink hover:bg-surface-input"
            >
                <flux:icon name="eye-slash" class="size-4 shrink-0" />
                <span>{{ __('Not interested') }}</span>
            </button>
        </div>
    </div>
@endif
