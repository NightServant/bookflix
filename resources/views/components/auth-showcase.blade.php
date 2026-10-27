@php
    $books = \App\Models\Media::showcaseCovers();

    // Centre book on top, the others fanned behind it; positions are the book's own centre offset in rem plus a roll.
    $fan = [['x' => '0rem', 'y' => '0rem', 'r' => '0deg', 'z' => 30], ['x' => '-7.5rem', 'y' => '1.25rem', 'r' => '-9deg', 'z' => 20], ['x' => '7.5rem', 'y' => '1.25rem', 'r' => '9deg', 'z' => 10]];
@endphp

{{--
    The calm side of the auth split: a small fan of CSS books with real popular covers, and one line saying what Bookflix is for.
    Decorative: the covers have no alt text, and the titles are written out under the line. Large screens only (the layout hides it below lg).
--}}
<div {{ $attributes->class('flex h-full flex-col justify-between') }}>
    <x-app-logo href="{{ route('landing') }}" wire:navigate />

    <div class="my-10 flex flex-col items-center">
        @if (count($books))
            <div class="relative h-80 w-full max-w-md" aria-hidden="true">
                @foreach ($books as $i => $book)
                    <div class="absolute start-1/2 top-0 -ms-24" style="transform: translate({{ $fan[$i]['x'] }}, {{ $fan[$i]['y'] }}) rotate({{ $fan[$i]['r'] }}); z-index: {{ $fan[$i]['z'] }};">
                        <x-book-3d :src="$book['cover']" :title="$book['title']" size="lg" tilt="-12deg" />
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="max-w-md">
        <p class="text-section tracking-tight text-balance text-ink">{{ __('Every story you mean to read, on one calm shelf.') }}</p>
        @if (count($books))
            <p class="mt-3 text-meta text-ink-subtle">{{ __('Popular right now: :titles', ['titles' => collect($books)->pluck('title')->join(', ', __(' and '))]) }}</p>
        @endif
    </div>
</div>
