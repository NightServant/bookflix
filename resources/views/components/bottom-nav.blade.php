@php
    $items = [
        ['label' => __('Home'), 'icon' => 'home', 'route' => 'home', 'active' => ['home']],
        ['label' => __('Profile'), 'icon' => 'user', 'route' => 'profile.edit', 'active' => ['profile.edit', 'security.edit']],
    ];
@endphp

<nav aria-label="{{ __('Primary') }}" class="fixed inset-x-0 bottom-0 z-40 border-t border-hairline bg-surface-deep lg:hidden">
    <ul class="grid grid-cols-2">
        @foreach ($items as $item)
            @php $isCurrent = request()->routeIs(...$item['active']); @endphp
            <li>
                <a
                    href="{{ route($item['route']) }}"
                    wire:navigate
                    @if ($isCurrent) aria-current="page" @endif
                    class="flex flex-col items-center gap-1 py-3 text-meta {{ $isCurrent ? 'text-ink' : 'text-ink-muted' }}"
                >
                    <flux:icon :name="$item['icon']" class="size-6" />
                    <span>{{ $item['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
