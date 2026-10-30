@props(['media', 'classCode' => true])

{{--
    One quiet line of facts: type, year, length, age rating, Dewey-inspired class code (public primary only; `:class-code="false"` leaves it out where a breadcrumb already says it).
    Missing parts are left out; the dots between parts are CSS, so there is never a stray separator.
    Eager-load `mediaType` and `publicPrimaryDewey.deweyClass` when rendering fetched media, and `additionalTypes` where the other types should show.
--}}
@php
    $length = match (true) {
        $media->page_count > 0 => trans_choice(':count page|:count pages', $media->page_count),
        $media->chapter_count > 0 => trans_choice(':count chapter|:count chapters', $media->chapter_count),
        default => null,
    };

    $code = $classCode ? $media->publicPrimaryDewey?->deweyClass?->code : null;

    // A book that is more than one type names them all, its main type first ("Graphic Novel & Comic Book"), when they were loaded.
    $types = Illuminate\Support\Arr::join([$media->mediaType->name, ...($media->relationLoaded('additionalTypes') ? $media->additionalTypes->pluck('name')->all() : [])], ', ', ' & ');

    $parts = collect([
        $types,
        $media->publication_year,
        $length,
        $media->age_rating->label(),
        $code ? new Illuminate\Support\HtmlString('<span class="sr-only">'.e(__('Class')).' </span>'.e($code)) : null,
    ])->filter(fn ($part) => filled($part))->values();
@endphp

@if ($parts->isNotEmpty())
    <ul {{ $attributes->class('flex flex-wrap items-center text-meta text-ink-subtle') }}>
        @foreach ($parts as $part)
            <li class="tabular-nums [&:not(:last-child)]:after:mx-2 [&:not(:last-child)]:after:content-['·']">{{ $part }}</li>
        @endforeach
    </ul>
@endif
