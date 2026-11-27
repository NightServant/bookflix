@props(['label'])

{{--
    A region for an admin table that can be wider than the screen: Flux's own scroll area is not reachable by keyboard, so the scrolling happens here (the region is `relative`, so a screen-reader-only span in a header cell is clipped with the rest and cannot widen the page),
    on a focusable, named region (a person without a pointer can then scroll it with the arrow keys).
--}}
<div role="region" tabindex="0" aria-label="{{ $label }}" {{ $attributes->class('focus-ring relative overflow-x-auto rounded-control [&_ui-table-scroll-area]:overflow-visible') }}>
    {{ $slot }}
</div>
