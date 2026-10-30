@props(['shelves', 'bleed' => false])

{{--
    The editorial collections (staff picks) as themed shelves, one row each: the collection's name as the title, "Staff pick" as the eyebrow,
    its description under the title, its stories as tiles with the shared preview card, and "See all" opening the collection. `shelves` are the
    Shelf objects EditorialCollections builds (a list of media each, eager-loaded for the tile and its preview). `bleed` is the Home band; without
    it the rows are the rounded shelves of Discover, with a gap between them. They are rows, never a grid of cards.
--}}
@if ($shelves->isNotEmpty())
    <div {{ $attributes->class(['flex flex-col gap-4' => ! $bleed]) }}>
        @foreach ($shelves as $shelf)
            <x-shelf
                :title="$shelf->title"
                :eyebrow="$shelf->reasonLabel"
                :description="$shelf->description"
                :href="$shelf->href"
                :id="'shelf-'.$shelf->key"
                :previews="'quick-view-'.$shelf->key"
                :bleed="$bleed"
                wire:key="{{ $shelf->key }}"
            >
                @foreach ($shelf->items as $item)
                    <li wire:key="{{ $shelf->key }}-{{ $item->id }}">
                        <x-story-preview :media="$item">
                            <x-cover-tile :media="$item" />
                        </x-story-preview>
                    </li>
                @endforeach
            </x-shelf>
        @endforeach
    </div>
@endif
