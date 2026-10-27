<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas text-ink antialiased">
        <div class="grid min-h-svh lg:grid-cols-2">
            {{-- Desktop only: the calm side. --}}
            <aside class="hidden border-e border-hairline bg-surface-deep p-12 lg:block xl:p-16" aria-label="{{ config('app.name', 'Bookflix') }}">
                <x-auth-showcase />
            </aside>

            <main id="main" class="flex flex-col items-center justify-center gap-8 px-gutter py-10 lg:px-12">
                <x-app-logo href="{{ route('landing') }}" wire:navigate class="lg:hidden" />

                <div class="w-full max-w-md rounded-card bg-surface-card p-6 sm:p-8">
                    {{ $slot }}
                </div>
            </main>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @livewireScriptConfig
        @fluxScripts
    </body>
</html>
