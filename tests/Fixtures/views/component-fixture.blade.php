<!DOCTYPE html>
<html lang="en" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Component fixture</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-canvas text-ink antialiased">
        <main class="px-gutter py-8">
            {!! Blade::render($markup, $data) !!}
        </main>

        <nav aria-label="Fixture">
            <a href="/_test/elsewhere" wire:navigate class="focus-ring">Go elsewhere</a>
        </nav>

        @livewireScriptConfig
    </body>
</html>
