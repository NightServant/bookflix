@props(['media'])

{{--
    One search result in list view: a small 2:3 cover, the linked title, creators, the facts line, a two-line blurb and up to four
    genre chips. The grid view of the same results is a plain <x-cover-tile>. The cover also links to the story, but only for
    pointers: it is out of the tab order and hidden from screen readers, so the title is the one link.
    Rows are rendered from fetched media, and `preventLazyLoading` is on, so eager-load these first:
    `mediaType`, `publicPrimaryDewey.deweyClass` (story-info), `creators` and `genres`. Give each row a `wire:key` in the loop.
--}}
@php
    $creators = $media->creators->sortBy('pivot.position')->pluck('name')->unique()->implode(', ');
    $story = route('stories.show', $media->slug);
@endphp

<article {{ $attributes->class('flex gap-4 rounded-card bg-surface-deep p-3 sm:p-4') }}>
    <a href="{{ $story }}" wire:navigate aria-hidden="true" tabindex="-1" class="block aspect-[2/3] w-16 shrink-0 self-start overflow-hidden rounded-card bg-surface-card sm:w-20">
        @if ($media->cover_url)
            <img src="{{ $media->cover_url }}" alt="" width="80" height="120" loading="lazy" decoding="async" class="size-full object-cover">
        @else
            <span class="flex size-full items-center justify-center">
                <flux:icon name="book-open" class="size-6 text-ink-muted" />
            </span>
        @endif
    </a>

    <div class="min-w-0 flex-1">
        <h3 class="text-lead text-ink">
            <a href="{{ $story }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center rounded-control">{{ $media->title }}</a>
        </h3>

        @if ($creators !== '')
            <p data-creators class="text-meta text-ink-subtle">{{ $creators }}</p>
        @endif

        <x-story-info :media="$media" class="mt-1" />

        @if ($media->description)
            <p class="mt-2 line-clamp-2 text-body text-ink-subtle">{{ $media->description }}</p>
        @endif

        @if ($media->genres->isNotEmpty())
            <ul aria-label="{{ __('Genres') }}" class="mt-3 flex flex-wrap gap-2">
                @foreach ($media->genres->take(4) as $genre)
                    <li wire:key="genre-{{ $genre->id }}" class="rounded-full bg-surface-input px-3 py-1 text-legal text-ink-subtle">{{ $genre->name }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</article>
