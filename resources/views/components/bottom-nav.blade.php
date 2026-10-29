<nav aria-label="{{ __('Primary') }}" class="fixed inset-x-0 bottom-0 z-40 border-t border-hairline bg-surface-deep lg:hidden">
    <ul class="flex">
        @foreach (config('bookflix.navigation') as $item)
            @php $isCurrent = request()->routeIs(...$item['active']); @endphp
            <li class="flex-1">
                <a
                    href="{{ route($item['route']) }}"
                    wire:navigate
                    @if ($isCurrent) aria-current="page" @endif
                    class="focus-ring flex flex-col items-center gap-1 py-3 text-meta {{ $isCurrent ? 'text-ink' : 'text-ink-muted' }}"
                >
                    <flux:icon :name="$item['icon']" class="size-6" />
                    <span>{{ __($item['label']) }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
