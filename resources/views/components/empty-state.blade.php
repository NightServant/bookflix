@props(['title', 'heading' => 'h2', 'book' => null])

{{--
    An empty screen or section: a tilted CSS book (`book` or the title set on its cover), what is going on in `title` and the slot, and a
    way out. Put the explanation and the actions in the slot; wrap the actions in a `mt-6 flex flex-wrap gap-3` div.
    `heading` is the tag of the title (`h1` when the state is the whole page). The book is decorative.
--}}
<div {{ $attributes->class('flex flex-col gap-8 sm:flex-row sm:items-center sm:gap-12') }}>
    <div aria-hidden="true" class="ps-3">
        <x-book-3d size="md" tilt="24deg" :title="$book ?? $title" class="scale-90 sm:scale-100" />
    </div>

    <div class="min-w-0 max-w-prose">
        <{{ $heading }} class="text-section tracking-tight text-ink">{{ $title }}</{{ $heading }}>
        {{ $slot }}
    </div>
</div>
