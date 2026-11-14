{{--
    The admin shell: a sidebar of the places an admin works in (config `bookflix.admin_navigation`), and a top bar with a menu button that opens it as
    a drawer on small screens. It carries what an admin needs from the reader shell (skip link, head, toasts, the islands stack) and none of the reader
    pieces (top navbar, bottom nav, command palette, library listener). Chosen by layouts/app for any admin account.
--}}
@php
    $counts = \App\Admin\AdminCounts::get();
    $admin = auth()->user();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas text-ink antialiased">
        <a
            href="#main"
            class="focus-ring fixed start-gutter top-2 z-50 flex -translate-y-20 opacity-0 focus:translate-y-0 focus:opacity-100 min-h-11 items-center rounded-control border border-hairline bg-surface-card px-4 text-meta text-ink shadow-lg"
        >{{ __('Skip to content') }}</a>

        {{-- Escape closes the drawer (Flux closes it only from its buttons) and gives focus back to the menu button that opened it. --}}
        <flux:sidebar sticky collapsible="mobile" class="border-e border-hairline bg-surface-deep lg:bg-canvas" :aria-label="__('Admin')" x-on:keydown.escape.window="if ($el.hasAttribute('data-flux-sidebar-on-mobile') && ! $el.hasAttribute('data-flux-sidebar-collapsed-mobile')) { $el.querySelector('[data-flux-sidebar-collapse] button').click(); $nextTick(() => document.querySelector('[data-flux-sidebar-toggle]').focus()) }">
            <flux:sidebar.header>
                <span class="flex items-center gap-2">
                    <x-app-logo :href="route('admin.index')" wire:navigate />
                    <flux:badge size="sm" color="zinc">{{ __('Admin') }}</flux:badge>
                </span>
                <flux:sidebar.collapse class="focus-ring min-h-11 min-w-11 lg:hidden" :aria-label="__('Close menu')" />
            </flux:sidebar.header>

            <flux:sidebar.nav :aria-label="__('Admin sections')">
                @foreach (config('bookflix.admin_navigation') as $section)
                    @if ($section['group'])
                        <flux:sidebar.group :heading="__($section['group'])" class="mt-4 [&>div:first-child]:text-legal [&>div:first-child]:tracking-wider [&>div:first-child]:uppercase">
                            @foreach ($section['items'] as $item)
                                @include('layouts.admin-item')
                            @endforeach
                        </flux:sidebar.group>
                    @else
                        @foreach ($section['items'] as $item)
                            @include('layouts.admin-item')
                        @endforeach
                    @endif
                @endforeach
            </flux:sidebar.nav>

            <flux:spacer />

            <div class="flex items-center gap-3 border-t border-hairline px-2 pt-4" data-test="admin-account">
                <flux:avatar class="shrink-0" :name="$admin->name" :initials="$admin->initials()" :src="$admin->avatarUrl()" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-meta font-medium text-ink">{{ $admin->name }}</p>
                    <div class="flex items-center gap-1 text-legal text-ink-subtle">
                        <a href="{{ route('profile.edit') }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center rounded-control px-1 hover:text-ink">{{ __('Account') }}</a>
                        <span aria-hidden="true">·</span>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="focus-ring inline-flex min-h-11 cursor-pointer items-center rounded-control px-1 hover:text-ink" data-test="logout-button">{{ __('Sign out') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </flux:sidebar>

        <flux:header class="border-b border-hairline bg-canvas px-4! lg:hidden">
            <flux:sidebar.toggle class="focus-ring min-h-11 min-w-11 lg:hidden" icon="bars-2" inset="left" :aria-label="__('Open menu')" />
            <x-app-logo :href="route('admin.index')" wire:navigate class="ms-2" />
            <flux:spacer />
            <a href="{{ route('profile.edit') }}" wire:navigate class="focus-ring inline-flex min-h-11 min-w-11 items-center justify-center rounded-control" aria-label="{{ __('Account') }}">
                <flux:avatar size="sm" :name="$admin->name" :initials="$admin->initials()" :src="$admin->avatarUrl()" />
            </a>
        </flux:header>

        <main id="main" tabindex="-1" data-flux-main class="min-w-0 px-4 pb-12 outline-none [grid-area:main] sm:px-6 lg:px-10">
            {{ $slot }}
        </main>

        {{-- Data a page ships once with its first load (see <x-filter-islands>); a Livewire update never re-sends it. --}}
        @stack('islands')

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @livewireScriptConfig
        @php(app('livewire')->forceAssetInjection())
    </body>
</html>
