@props(['shelves', 'links' => true])

{{--
    One <x-shelf bleed> per class shelf (see ClassShelves), titled with the class and, unless `links` is off, with "See all" to the class page.
    A page that lists only its own stories (the library) turns the links off: the class page lists the whole catalog.
--}}

@foreach ($shelves as $shelf)
    <x-shelf
        :title="$shelf->class->name"
        :href="$links ? route('explore.show', $shelf->class->code) : null"
        :id="'shelf-'.$shelf->class->id"
        bleed
        wire:key="shelf-{{ $shelf->class->id }}"
    >
        @foreach ($shelf->media as $media)
            <li wire:key="shelf-{{ $shelf->class->id }}-{{ $media->id }}">
                <x-cover-tile :media="$media" />
            </li>
        @endforeach
    </x-shelf>
@endforeach
