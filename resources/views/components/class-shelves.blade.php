@props(['shelves'])

@foreach ($shelves as $shelf)
    <section aria-labelledby="shelf-{{ $shelf->class->id }}" class="mt-8 first:mt-0" wire:key="shelf-{{ $shelf->class->id }}">
        <div class="flex items-center justify-between gap-4">
            <h3 id="shelf-{{ $shelf->class->id }}" class="text-lead text-ink">{{ $shelf->class->name }}</h3>
            <a
                href="{{ route('explore.show', $shelf->class->code) }}"
                wire:navigate
                aria-label="{{ __('See all in :shelf', ['shelf' => $shelf->class->name]) }}"
                class="focus-ring inline-flex min-h-11 items-center rounded-control px-2 text-meta text-link hover:underline"
            >{{ __('See all') }}</a>
        </div>

        <ul tabindex="0" aria-label="{{ __('Stories in :shelf', ['shelf' => $shelf->class->name]) }}" class="focus-ring mt-3 flex gap-4 overflow-x-auto rounded-card pb-2">
            @foreach ($shelf->media as $media)
                <li class="shrink-0" wire:key="shelf-{{ $shelf->class->id }}-{{ $media->id }}">
                    <x-cover-tile :media="$media" />
                </li>
            @endforeach
        </ul>
    </section>
@endforeach
