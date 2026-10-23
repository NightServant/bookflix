<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas text-ink antialiased">
        <flux:header class="border-b border-hairline bg-canvas px-gutter!">
            <x-app-logo href="{{ route('home') }}" wire:navigate />

            <flux:navbar class="-mb-px ms-6 max-lg:hidden">
                <flux:navbar.item icon="home" :href="route('home')" :current="request()->routeIs('home')" wire:navigate>
                    {{ __('Home') }}
                </flux:navbar.item>
                <flux:navbar.item icon="user" :href="route('profile.edit')" :current="request()->routeIs('profile.edit', 'security.edit')" wire:navigate>
                    {{ __('Profile') }}
                </flux:navbar.item>
            </flux:navbar>

            <flux:spacer />

            <x-desktop-user-menu />
        </flux:header>

        {{ $slot }}

        <x-bottom-nav />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
