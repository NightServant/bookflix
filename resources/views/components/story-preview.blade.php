@props(['media'])

{{--
    A story tile with a hover preview and, on touch devices, a quick view. Wrap a trigger (usually an <x-cover-tile>, passed as the
    slot) inside <x-story-previews> (a shelf with `previews`): the card that opens beside it after the pointer rests on it, or the
    keyboard reaches it, for 300ms, is the host's one shared card, and so is the touch dialog behind the visible "Quick view" button
    (it also names the story for screen readers). Both show <x-story-summary>, which this tile keeps once, inert, in a <template> for the host to clone.
    A touch pointer never opens the card; the button is hidden except on coarse pointers.
    Eager-load what <x-story-summary> reads, and give a shelf item a `wire:key` as usual.
--}}
<div
    data-story-preview
    data-preview-label="{{ __('Preview of :title', ['title' => $media->title]) }}"
    x-init="link($el).setAttribute('aria-expanded', 'false')"
    {{ $attributes }}
>
    <div
        @pointerenter="enter($event, $el.parentElement)"
        @pointerleave="leave($event)"
        @focusin="focus($event, $el.parentElement)"
        @focusout="blur($event)"
        @keydown.tab="enterCard($event, $el.parentElement)"
    >{{ $slot }}</div>

    <template><x-story-summary :media="$media" /></template>

    <div class="mt-2 hidden pointer-coarse:block">
        <button
            type="button"
            @click="quickView($el.closest('[data-story-preview]'))"
            class="focus-ring inline-flex min-h-11 items-center gap-2 rounded-control bg-surface-input px-3 text-meta text-ink hover:bg-hairline"
        >
            <flux:icon name="eye" class="size-5" />
            <span>{{ __('Quick view') }}<span class="sr-only"> {{ __('of :title', ['title' => $media->title]) }}</span></span>
        </button>
    </div>
</div>
