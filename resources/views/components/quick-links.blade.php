@props([
    'links',
    'label' => null,
])

{{--
    A row of shortcut cards (Continue reading, New releases, ...). `links` is a list of `label`, `route` (a route name without
    parameters) and `icon` (a Heroicons name). The cards are flux:card links: 44px targets, equal heights in a row (the grid rows
    share the tallest card), and one plain list in DOM order, so the keyboard order is the reading order. `label` names the nav.
--}}
<nav aria-label="{{ $label ?? __('Quick links') }}" {{ $attributes }}>
    <ul class="grid auto-rows-fr grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($links as $link)
            <li wire:key="quick-link-{{ $link['route'] }}-{{ $loop->index }}">
                <a href="{{ route($link['route']) }}" wire:navigate class="group focus-ring block h-full rounded-card">
                    <flux:card size="sm" class="h-full rounded-card border-hairline bg-surface-card p-2! ps-4! group-hover:bg-surface-input">
                        <span class="flex min-h-11 items-center gap-3">
                            <flux:icon :name="$link['icon']" class="size-5 shrink-0 text-ink-subtle group-hover:text-ink" />
                            <span class="text-body font-medium text-ink">{{ $link['label'] }}</span>
                        </span>
                    </flux:card>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
