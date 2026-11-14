{{-- One sidebar item: `$item` and `$counts` come from layouts/admin. The count is a badge beside the label; a zero count shows none. --}}
@php($count = isset($item['count']) ? ($counts[$item['count']] ?? 0) : 0)
<flux:sidebar.item
    :icon="$item['icon']"
    :href="route($item['route'])"
    :current="request()->routeIs(...$item['active'])"
    :badge="$count > 0 ? \Illuminate\Support\Number::format($count) : null"
    :aria-current="request()->routeIs(...$item['active']) ? 'page' : null"
    wire:navigate
    class="focus-ring min-h-11 lg:h-10"
>{{ __($item['label']) }}</flux:sidebar.item>
