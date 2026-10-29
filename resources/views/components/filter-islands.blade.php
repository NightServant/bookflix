@props(['panel', 'years'])

@php
    // `panel` is a closure, so an update (which discards what is pushed) never builds the tree at all.
    $first = ! \Livewire\Livewire::isLivewireRequest();
@endphp

{{--
    The one data island of a page that filters: the taxonomy as JSON (genre tree with live counts, themes, tropes... as `$panel`) and the markup of
    the filter panel in an inert <template>, pushed to the layout's `islands` stack. Both are sent with the first page load only: a Livewire update
    renders the component again but never the layout, so the tree is not sent per update, and the sidebar and the phone sheet share these two
    instead of carrying a copy each. The panel is built from them in the browser (resources/js/filter-panel.js).
--}}
@if ($first)
    @push('islands')
        <script type="application/json" id="filter-data">@json(value($panel))</script>
        <template id="filter-panel-template"><x-filter-panel :years="$years" /></template>
    @endpush
@endif
