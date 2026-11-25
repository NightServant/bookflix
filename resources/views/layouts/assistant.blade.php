{{--
    The assistant's own shell: a focused, full-height app frame like a chat app's. No top header, no navbar, no bottom bar and no command palette;
    the page brings its sidebar (or, on a phone, a slim top bar) with the way back to Home, and its own `<main id="main">`. The rest is the app
    shell's: the shared head, the skip link, the page islands, the library listener the answer cards' Add to Library buttons need, the toasts.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-dvh bg-canvas text-ink antialiased">
        <a
            href="#main"
            class="focus-ring fixed start-gutter top-2 z-50 flex -translate-y-20 opacity-0 focus:translate-y-0 focus:opacity-100 min-h-11 items-center rounded-control border border-hairline bg-surface-card px-4 text-meta text-ink shadow-lg"
        >{{ __('Skip to content') }}</a>

        {{ $slot }}

        {{-- Data a page ships once with its first load (see <x-filter-islands>); a Livewire update never re-sends it. --}}
        @stack('islands')

        {{-- Hears the `library-add` event of the Add to Library buttons (<x-library-add>) and adds the story. --}}
        <livewire:library-listener />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        {{-- A redirect that carries a `toast` shows it once the page is up. --}}
        @if (session('toast'))
            <div x-data x-init="$nextTick(() => $flux.toast({ text: @js(session('toast')) }))" hidden></div>
        @endif

        @livewireScriptConfig
        {{-- Flux's script is bundled into app.js (static, compressed, no parser-blocking tag); this keeps the rest of @fluxScripts. --}}
        @php(app('livewire')->forceAssetInjection())
    </body>
</html>
