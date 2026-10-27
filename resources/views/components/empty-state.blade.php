@props(['title', 'heading' => 'h2', 'book' => null])

{{--
    An empty screen or section: a tilted CSS book (a popular cover from the catalog, or `book` / the title set on a fallback cover), what is going on in `title` and the slot, and a
    way out. Put the explanation and the actions in the slot; wrap the actions in a `mt-6 flex flex-wrap gap-3` div.
    `heading` is the tag of the title (`h1` when the state is the whole page). The book is decorative.
--}}
@php
    // A real, popular cover reads as an invitation; the title set on a fallback book only when the catalog has no covers yet.
    $showcase = $book === null ? (\App\Models\Media::showcaseCovers()[0] ?? null) : null;
@endphp

<div {{ $attributes->class('flex flex-col gap-8 sm:flex-row sm:items-center sm:gap-12') }}>
    <div aria-hidden="true" class="ps-3">
        @if ($showcase)
            <x-book-3d size="md" tilt="24deg" :src="$showcase['cover']" :title="$showcase['title']" alt="" class="scale-90 opacity-80 sm:scale-100" />
        @else
            <x-book-3d size="md" tilt="24deg" :title="$book ?? $title" class="scale-90 sm:scale-100" />
        @endif
    </div>

    <div class="min-w-0 max-w-prose">
        <{{ $heading }} class="text-section tracking-tight text-ink">{{ $title }}</{{ $heading }}>
        {{ $slot }}
    </div>
</div>
