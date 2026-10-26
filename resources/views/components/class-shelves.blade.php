@props(['shelves'])

@foreach ($shelves as $shelf)
    <x-shelf
        :title="$shelf->class->name"
        :href="route('explore.show', $shelf->class->code)"
        :id="'shelf-'.$shelf->class->id"
        class="mt-8 first:mt-0"
        wire:key="shelf-{{ $shelf->class->id }}"
    >
        @foreach ($shelf->media as $media)
            <li wire:key="shelf-{{ $shelf->class->id }}-{{ $media->id }}">
                <x-cover-tile :media="$media" />
            </li>
        @endforeach
    </x-shelf>
@endforeach
