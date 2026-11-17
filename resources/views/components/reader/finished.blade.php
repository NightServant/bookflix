@props(['title', 'backUrl', 'next' => null, 'nextReadable' => false])

@php
    $creator = $next?->creators->sortBy('pivot.position')->first()?->name;
    $meta = collect([$creator, $next?->publication_year])->filter()->implode(' · ');
@endphp

{{--
    The end of the book (frame d04): a scrim over the page and one card. The page shows it when an engine's `reader:relocate` says `atEnd`, and
    the story is marked completed by the progress save, not by this card. "Next in the series" is there only when the series has a next story;
    its button is the red "Start reading" when that story has a file this reader may open, else a quiet "See the story".
--}}
<div
    data-reader="finished"
    x-show="finished"
    x-trap="finished"
    x-cloak
    style="display: none"
    role="dialog"
    aria-modal="true"
    aria-labelledby="reader-finished-title"
    @keydown.escape.window="finished = false"
    {{ $attributes->class('fixed inset-x-0 top-16 bottom-22 z-30 flex items-center justify-center bg-canvas/60 p-4') }}
>
    <div class="w-full max-w-140 rounded-card border border-hairline bg-surface-card p-8">
        <p class="text-meta text-ink-muted">{{ __('You finished') }}</p>
        <h2 id="reader-finished-title" class="mt-4 text-section text-ink text-balance">{{ $title }}</h2>
        <p class="mt-6 text-body text-ink-subtle">{{ __('Marked as completed in your library.') }}</p>

        @if ($next)
            <hr class="my-5 border-hairline">

            <div class="flex items-center gap-4">
                <span class="relative block h-24 w-16 shrink-0 overflow-hidden rounded-control bg-surface-input">
                    @if ($next->cover_url)
                        <img src="{{ $next->cover_url }}" alt="" width="64" height="96" loading="lazy" decoding="async" class="size-full object-cover">
                    @else
                        <span class="flex size-full items-center justify-center text-ink"><flux:icon name="book-open" variant="outline" class="size-6" /></span>
                    @endif
                </span>
                <div class="min-w-0">
                    <p class="text-legal text-ink-muted">{{ __('Next in the series') }}</p>
                    <p class="text-lead text-ink">{{ $next->title }}</p>
                    @if ($meta !== '')
                        <p class="text-meta text-ink-muted">{{ $meta }}</p>
                    @endif
                </div>
            </div>
        @endif

        <div class="mt-6 flex flex-wrap gap-3">
            <x-cta variant="secondary" :href="$backUrl">{{ __('Back to the story') }}</x-cta>

            @if ($next && $nextReadable)
                <x-cta :href="route('reader.show', $next->slug)">{{ __('Start reading') }}</x-cta>
            @elseif ($next)
                <x-cta variant="secondary" :href="route('stories.show', $next->slug)">{{ __('See the story') }}</x-cta>
            @endif
        </div>
    </div>
</div>
