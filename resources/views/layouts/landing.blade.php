@props(['title' => null, 'description' => null])

{{--
    The public landing page's own layout: no app header or bottom nav, a skip link, one `main` landmark and the landing footer.
    Dark-only like the rest of the app. Anchors scroll smoothly unless the reader asked for less motion.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark scroll-smooth motion-reduce:scroll-auto">
    <head>
        @include('partials.head', ['title' => $title])

        @if ($description)
            <meta name="description" content="{{ $description }}">
            <meta property="og:title" content="{{ $title ? $title.' - '.config('app.name') : config('app.name') }}">
            <meta property="og:description" content="{{ $description }}">
            <meta property="og:type" content="website">
        @endif
    </head>
    <body class="min-h-screen bg-canvas text-ink antialiased">
        {{-- Parked above the viewport until focused, then it sits over the header at the logo's gutter. --}}
        <a
            href="#main"
            class="focus-ring fixed start-gutter top-2 z-50 flex -translate-y-20 opacity-0 focus:translate-y-0 focus:opacity-100 min-h-11 items-center rounded-control border border-hairline bg-surface-card px-4 text-meta text-ink"
        >{{ __('Skip to content') }}</a>

        <x-landing.header />

        <main id="main" tabindex="-1" class="outline-hidden">
            {{ $slot }}
        </main>

        <x-landing.footer />

        @livewireScriptConfig
    </body>
</html>
