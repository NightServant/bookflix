@props([
    'media',
])

{{--
    The short version of a story that <x-story-preview> keeps for the shared card (hover) and quick view (touch) to clone: a small cover, the title,
    <x-story-info>, a three-line blurb and the ctas: a primary "View story" with, once the library exists, an icon-only add button beside it.
    Eager-load `mediaType` and `publicPrimaryDewey.deweyClass` on fetched media (story-info reads them).
    "Add to Library" dispatches a `library-add` event with the media id (rendered only once the Phase 5 `library.store` route exists), as <x-hero> does.
--}}
<div data-story-summary {{ $attributes }}>
    <div class="flex gap-4">
        <span class="block aspect-[2/3] w-24 shrink-0 self-start overflow-hidden rounded-card bg-surface-deep">
            @if ($media->cover_url)
                <img src="{{ $media->cover_url }}" alt="" width="96" height="144" loading="lazy" decoding="async" class="size-full object-cover">
            @else
                <x-cover-fallback :title="$media->title" />
            @endif
        </span>

        <div class="min-w-0 flex-1">
            <h2 class="text-lead text-ink">{{ $media->title }}</h2>
            <x-story-info :media="$media" class="mt-1" />

            @if ($media->description)
                <p class="mt-3 line-clamp-3 text-meta text-ink-subtle">{{ $media->description }}</p>
            @endif
        </div>
    </div>

    <div class="mt-4 flex gap-2">
        <x-cta :href="route('stories.show', $media->slug)" wire:navigate class="flex-1">{{ __('View story') }}</x-cta>
        @if (Route::has('library.store'))
            <x-cta variant="secondary" @click="$dispatch('library-add', { id: {{ $media->id }} })" aria-label="{{ __('Add to Library') }}" class="w-11 shrink-0 ps-0! pe-0!">
                <flux:icon name="plus" class="size-5" />
            </x-cta>
        @endif
    </div>
</div>
