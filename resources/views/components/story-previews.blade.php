@props([
    'name' => null,
])

{{--
    The one preview card and the one quick view that every <x-story-preview> inside it shares: wrap a shelf (<x-shelf previews="...">
    does this) and its tiles each hold only a trigger and an inert <template> of their summary, instead of a card and a dialog apiece.
    A home with two shelves of twenty stories is then one card and one dialog per shelf, not forty of each, and one set of
    window listeners per shelf, not one per tile. The tile in use is the whole state: its summary is cloned into the card (hover or keyboard focus,
    after 300ms) or into the dialog (the touch "Quick view" button), and `name` is that dialog's name, unique per page.
    Built on @alpinejs/ui's popover (root and panel; the triggers are the tiles' own links, so the plugin's button part is left out and
    the links stay links, `__buttonEl` follows the active tile) with x-anchor, `fixed` so no scroll-snap track clips it, and x-motion-expand.
    Without a `name` it is only the slot, so <x-shelf> can wrap every shelf in it.
    The card sits after the slot rather than beside its tile, so Tab from the tile enters it and Tab from its last control carries on past the tile (see storyPreviews).
--}}
@if ($name === null)
    {{ $slot }}
@else
    <div x-data="storyPreviews(@js($name))" @keydown.escape.window="dismiss()" data-story-previews>
        <div
            x-popover
            x-model="open"
            @keydown.escape.capture="open || $event.stopPropagation()"
            x-effect="mark(active, open); $data.__buttonEl = active ? link(active) : undefined"
        >
            {{ $slot }}

            <div
                x-popover:panel
                x-anchor.bottom-start.offset.8.fixed="active?.firstElementChild"
                x-motion-expand="open"
                role="dialog"
                data-story-card
                :aria-label="active?.dataset.previewLabel"
                @keydown.tab.capture="leaveCard($event)"
                @pointerenter="hold()"
                @pointerleave="leave($event)"
                @focusout="blur($event)"
                style="display: none"
                class="z-30 w-96 max-w-[calc(100vw-2rem)] rounded-card border border-hairline bg-surface-card p-4"
            >
                <div wire:ignore x-effect="fill($el, active)"></div>
            </div>
        </div>

        <x-quick-view :name="$name" />
    </div>
@endif
