@props([
    'media',
    'titleId' => null,
])

{{--
    The short version of a story that <x-story-preview> (hover) and <x-quick-view> (touch) share: a small cover, the title,
    <x-story-info>, a three-line blurb and the two ctas. `title-id` is the id the surrounding dialog names itself by.
    Eager-load `mediaType` and `publicPrimaryDewey.deweyClass` on fetched media (story-info reads them).
    "Add to Library" dispatches a `library-add` event with the media id (rendered only once the Phase 5 `library.store` route exists), as <x-hero> does.
--}}
<div {{ $attributes }}>
    <div class="flex gap-4">
        <span class="block aspect-[2/3] w-24 shrink-0 self-start overflow-hidden rounded-card bg-surface-deep">
            @if ($media->cover_url)
                <img src="{{ $media->cover_url }}" alt="" width="96" height="144" loading="lazy" decoding="async" class="size-full object-cover">
            @else
                <x-cover-fallback :title="$media->title" />
            @endif
        </span>

        <div class="min-w-0 flex-1">
            <h2 @if ($titleId) id="{{ $titleId }}" @endif class="text-lead text-ink">{{ $media->title }}</h2>
            <x-story-info :media="$media" class="mt-1" />

            @if ($media->description)
                <p class="mt-3 line-clamp-3 text-meta text-ink-subtle">{{ $media->description }}</p>
            @endif
        </div>
    </div>

    <div class="mt-4 flex flex-col gap-2">
        <x-cta :href="route('stories.show', $media->slug)" wire:navigate class="w-full">{{ __('View story') }}</x-cta>
        @if (Route::has('library.store'))
            <x-cta variant="secondary" @click="$dispatch('library-add', { id: {{ $media->id }} })" class="w-full">{{ __('Add to Library') }}</x-cta>
        @endif
    </div>
</div>
