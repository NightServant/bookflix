@props([
    'links',
    'label' => null,
])

{{--
    A row of shortcut chips (Continue reading, My collections, ...). `links` is a list of `label`, `route` (a route name without
    parameters) and `icon` (a Heroicons name). Each is a 44px-high control (taller if its label wraps) with a hairline border in one
    wrapping list, in DOM order, so the keyboard order is the reading order. `label` names the nav.
--}}
<nav aria-label="{{ $label ?? __('Quick links') }}" {{ $attributes }}>
    <ul class="flex flex-wrap gap-2">
        @foreach ($links as $link)
            <li wire:key="quick-link-{{ $link['route'] }}-{{ $loop->index }}">
                <a href="{{ route($link['route']) }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center gap-2 rounded-control border border-hairline bg-surface-input px-3 py-1 text-meta text-ink hover:bg-hairline">
                    <flux:icon :name="$link['icon']" class="size-4 shrink-0 text-ink-subtle" />
                    <span>{{ $link['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
