<!DOCTYPE html>
<html lang="en" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Book fixture</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-surface-deep text-ink antialiased">
        {{-- Test-only gallery of <x-book-3d>: every size, a tilt, a missing and a broken cover; `$scene` adds the WebGL scene to the xl book (every book when it is `all`: slow under software GL). --}}
        <main class="space-y-12 px-gutter py-10">
            <section class="flex flex-wrap items-end gap-12" data-test="sizes">
                @foreach (['sm', 'md', 'lg', 'xl'] as $i => $size)
                    <x-book-3d :src="$covers[$i]" :size="$size" :scene="$scene && ($size === 'xl' || $scene === 'all')" data-test="book-{{ $size }}" />
                @endforeach
            </section>

            <section class="flex flex-wrap items-end gap-12" data-test="variants">
                <x-book-3d :src="$covers[1]" size="lg" tilt="18deg" :scene="$scene === 'all'" data-test="book-spine" />
                <x-book-3d :src="$covers[0]" size="lg" tilt="150deg" :scene="$scene === 'all'" data-test="book-back" />
                <x-book-3d :src="$covers[2]" size="lg" tilt="0deg" interactive data-test="book-interactive" />
                <x-book-3d size="lg" title="No Cover Story" data-test="book-missing" />
                <x-book-3d src="/_test/missing.jpg" size="lg" title="Broken Cover Story" data-test="book-broken" />
            </section>
        </main>

        <nav aria-label="Fixture">
            <a href="/_test/elsewhere" wire:navigate class="focus-ring">Go elsewhere</a>
        </nav>

        @livewireScriptConfig
        @fluxScripts
    </body>
</html>
