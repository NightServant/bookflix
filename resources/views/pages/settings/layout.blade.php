{{--
    The settings shell: a side nav on lg (icon, label, a filled active row), a horizontally scrolling tab strip on phones, and the
    content column of cards. Pass the cards as the slot: each is an <x-settings-card>.
--}}
@php
    $items = [
        ['route' => 'profile.edit', 'icon' => 'user-circle', 'label' => __('Profile')],
        ['route' => 'preferences.edit', 'icon' => 'sparkles', 'label' => __('Preferences')],
        ['route' => 'privacy.edit', 'icon' => 'eye', 'label' => __('Privacy')],
        ['route' => 'security.edit', 'icon' => 'shield-check', 'label' => __('Security')],
    ];
@endphp

<div class="lg:grid lg:grid-cols-[13rem_minmax(0,1fr)] lg:items-start lg:gap-12">
    <nav aria-label="{{ __('Settings') }}" class="-mx-gutter mb-6 overflow-x-auto px-gutter [scrollbar-width:none] lg:sticky lg:top-6 lg:m-0 lg:overflow-visible lg:p-0">
        <ul class="flex gap-2 lg:flex-col lg:gap-1">
            @foreach ($items as $item)
                @php($current = request()->routeIs($item['route']))
                <li class="shrink-0">
                    <a
                        href="{{ route($item['route']) }}"
                        wire:navigate
                        @if ($current) aria-current="page" @endif
                        @class([
                            'focus-ring flex min-h-11 items-center gap-3 rounded-control px-4 text-meta font-medium whitespace-nowrap',
                            'bg-surface-card text-ink' => $current,
                            'text-ink-subtle hover:bg-surface-deep hover:text-ink' => ! $current,
                        ])
                    >
                        <flux:icon :name="$item['icon']" variant="outline" class="size-5 shrink-0" />
                        {{ $item['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    <div class="w-full max-w-2xl space-y-6">
        {{ $slot }}
    </div>
</div>
