@props([
    'title',
    'description' => null,
    'key' => null,
    'featured' => false,
    'kind' => 'covers',
    'count' => 10,
    'bleed' => false,
])

{{--
    What a lazy <x-shelf> shows until its stories arrive: the same section, padding and header height, the real title, and tiles of
    the real tile's size (`kind` is `covers` for <x-cover-tile> rows or `subjects` for <x-subject-tile> rows), so nothing moves when
    the shelf loads. A cover tile's title always takes two lines, and the touch-only quick view button is reserved as well.
    `bleed` matches <x-shelf bleed>. The pulse stops under reduced motion. `key` is the shelf key (it becomes `data-shelf-skeleton`).
--}}
<section
    aria-hidden="true"
    data-shelf-skeleton="{{ $key }}"
    {{ $attributes->class([
        'rounded-card p-4 sm:p-6' => ! $bleed,
        'py-8 lg:py-12' => $bleed,
        'bg-linear-to-r from-featured-from via-featured-via to-featured-to' => $featured,
        'bg-surface-deep' => ! $featured,
    ]) }}
>
    <div @class(['flex min-h-11 items-end justify-between gap-4', 'px-gutter' => $bleed])>
        <div class="min-w-0">
            <h2 class="{{ $bleed ? 'text-section' : 'text-lead' }} text-ink">{{ $title }}</h2>
            @if ($description)
                <p class="mt-1 text-meta text-ink-subtle">{{ $description }}</p>
            @endif
        </div>
    </div>

    <ul @class(['mt-3 flex gap-4 overflow-hidden motion-safe:animate-pulse', '-mx-1 p-1' => ! $bleed, 'mx-gutter py-1' => $bleed])>
        @foreach (range(1, $count) as $tile)
            @if ($kind === 'subjects')
                <li data-skeleton-tile class="h-40 w-56 shrink-0 rounded-card bg-surface-card sm:w-64"></li>
            @else
                <li data-skeleton-tile class="w-36 shrink-0 sm:w-40">
                    <div class="aspect-[2/3] rounded-card bg-surface-card"></div>
                    <div class="mt-2 min-h-[2lh] text-meta"></div>
                    <div class="mt-2 hidden h-11 pointer-coarse:block"></div>
                </li>
            @endif
        @endforeach
    </ul>
</section>
