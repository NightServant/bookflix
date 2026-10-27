{{--
    The signed-in app shell. `<main>` is full width, so a page can run bands edge to edge; the page's own content sits in the shared
    container (the header's own gutters, so left edges line up with the logo). A page that builds its own bands sets `flush`
    (`$this->view()->layoutData(['flush' => true])`) and brings the container itself: `px-gutter` inside every band.
--}}
<x-layouts::app.header :title="$title ?? null">
    <main id="main" tabindex="-1" class="pb-24 outline-none lg:pb-12">
        @if ($flush ?? false)
            {{ $slot }}
        @else
            <div class="px-gutter pt-6">
                {{ $slot }}
            </div>
        @endif
    </main>
</x-layouts::app.header>
