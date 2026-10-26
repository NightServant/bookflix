<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas text-ink antialiased">
        <flux:header class="border-b border-hairline bg-canvas px-gutter!">
            <x-app-logo href="{{ route('home') }}" wire:navigate />

            <flux:navbar class="-mb-px ms-6 max-lg:hidden">
                @foreach (config('bookflix.navigation') as $item)
                    <flux:navbar.item :icon="$item['icon']" :href="route($item['route'])" :current="request()->routeIs(...$item['active'])" wire:navigate>
                        {{ __($item['label']) }}
                    </flux:navbar.item>
                @endforeach
            </flux:navbar>

            <flux:spacer />

            <x-command-palette class="me-2 max-sm:hidden" />

            <x-desktop-user-menu />
        </flux:header>

        {{ $slot }}

        <x-bottom-nav />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @livewireScriptConfig
        @fluxScripts
    </body>
</html>
