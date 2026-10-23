<x-layouts::app.header :title="$title ?? null">
    <main class="px-gutter py-6 pb-24 lg:pb-12">
        {{ $slot }}
    </main>
</x-layouts::app.header>
