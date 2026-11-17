{{--
    The reader's own shell: no app header, no bottom navigation, a black canvas the page fills. The page brings its bars and its `<main>`; the head is
    the shared one, so the nonce, the fonts and the assets are the same as everywhere. The page title is the story's.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="h-dvh overflow-hidden bg-canvas text-ink antialiased">
        {{ $slot }}

        @livewireScriptConfig
        {{-- Flux's script is bundled into app.js (static, compressed, no parser-blocking tag); this keeps the rest of @fluxScripts. --}}
        @php(app('livewire')->forceAssetInjection())
    </body>
</html>
