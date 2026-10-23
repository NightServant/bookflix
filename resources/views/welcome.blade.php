<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => __('Welcome')])
    </head>
    <body class="min-h-screen bg-canvas text-ink antialiased">
        <header class="flex items-center justify-between px-gutter py-6">
            <x-app-logo href="{{ route('landing') }}" />

            <flux:button :href="route('login')" variant="ghost">{{ __('Sign in') }}</flux:button>
        </header>

        <main class="px-gutter py-24 text-center">
            <h1 class="text-hero text-ink">{{ __('Your Library. Every Story.') }}</h1>
            <p class="mx-auto mt-4 max-w-xl text-lead text-ink-subtle">
                {{ __('Bookflix keeps every book you own, read and love in one place.') }}
            </p>

            <div class="mt-10 flex flex-wrap items-center justify-center gap-4">
                @if (Route::has('register'))
                    <flux:button :href="route('register')" variant="primary" size="base">{{ __('Get started') }}</flux:button>
                @endif
                <flux:button :href="route('login')" size="base">{{ __('Sign in') }}</flux:button>
            </div>
        </main>
    </body>
</html>
