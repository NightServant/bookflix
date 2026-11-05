{{--
    The last card of "Your Collections" when the reader has exactly three collections, so the row is never three cards and none is hidden. Same size as
    <x-collection-card> and quiet (a dashed outline on the page's canvas), so it reads as an invitation and not as a collection; the whole card is
    the link to the collections page, where a new one is made, and is well over 44 px tall.
--}}
<a
    href="{{ route('collections.index') }}"
    wire:navigate
    data-new-collection
    {{ $attributes->class('group focus-ring flex w-60 shrink-0 flex-col items-center justify-center gap-2 rounded-card border border-dashed border-hairline p-3 text-ink-subtle hover:bg-surface-card hover:text-ink sm:w-68') }}
>
    <span aria-hidden="true" class="flex size-11 items-center justify-center rounded-full bg-surface-card group-hover:bg-surface-input">
        <flux:icon name="plus" class="size-5" />
    </span>
    <span class="text-body font-medium">{{ __('New collection') }}</span>
</a>
