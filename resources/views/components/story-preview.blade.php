@props(['media'])

{{--
    A hover preview: wrap a trigger (usually an <x-cover-tile>, passed as the slot) and a card opens beside it after the
    pointer rests on it, or the keyboard reaches it, for 300ms. It shows <x-story-summary> and closes on Escape, on blur,
    or a moment after the pointer leaves. A touch pointer never opens it: <x-quick-view> is the touch alternative.
    Built on @alpinejs/ui's popover (root and panel; the trigger is the slot's own link, so the plugin's button part is
    left out and the link stays a link) with x-anchor, `fixed` so no scroll-snap track clips it, and x-motion-expand.
    Eager-load what <x-story-summary> reads, and give a shelf item a `wire:key` as usual.
--}}
<div x-data="storyPreview" @keydown.escape.window="dismiss()" {{ $attributes }}>
    <div
        x-popover
        x-model="open"
        @pointerenter="enter($event)"
        @pointerleave="leave($event)"
        @focusin="focus($event)"
        @focusout="blur($event)"
    >
        <div
            x-ref="trigger"
            x-init="$data.__buttonEl = link()"
            x-effect="link().setAttribute('aria-expanded', open)"
        >{{ $slot }}</div>

        <div
            x-popover:panel
            x-anchor.bottom-start.offset.8.fixed="$refs.trigger"
            x-motion-expand="open"
            role="dialog"
            aria-label="{{ __('Preview of :title', ['title' => $media->title]) }}"
            style="display: none"
            class="z-30 w-96 max-w-[calc(100vw-2rem)] rounded-card border border-hairline bg-surface-card p-4"
        >
            <x-story-summary :media="$media" />
        </div>
    </div>
</div>
