@props(['covers' => []])

{{-- Step four's preview: the real genre and theme names as the chips the catalog uses, two picked, and a few stories under them. Decorative, so no links. --}}
@php
    $genres = ['Action', 'Adventure', 'Comedy', 'Drama', 'Fantasy', 'Horror', 'Mystery', 'Psychological', 'Romance', 'Science Fiction', 'Slice Of Life', 'Sports'];
    $themes = ['Coming Of Age', 'Isekai', 'Revenge', 'School', 'Survival', 'Time Travel', 'War'];
@endphp

<div class="landing-frame">
    <div class="landing-frame__bar"><span>{{ __('Genres & themes') }}</span><span class="tabular-nums">4 / 4</span></div>

    <div class="grid gap-2">
        <p class="text-legal tracking-widest text-ink-subtle uppercase">{{ __('Genres') }}</p>
        <div class="flex flex-wrap gap-2">
            @foreach ($genres as $genre)
                <x-chip :active="$genre === 'Fantasy'">{{ $genre }}</x-chip>
            @endforeach
        </div>
    </div>

    <div class="grid gap-2">
        <p class="text-legal tracking-widest text-ink-subtle uppercase">{{ __('Themes') }}</p>
        <div class="flex flex-wrap gap-2">
            @foreach ($themes as $theme)
                <x-chip :active="$theme === 'Isekai'">{{ $theme }}</x-chip>
            @endforeach
        </div>
    </div>

    <div class="landing-tree__covers mt-auto">
        @foreach (array_slice($covers, 0, 5) as $cover)
            <img src="{{ $cover['cover'] }}" width="96" height="144" alt="" loading="lazy" decoding="async" draggable="false">
        @endforeach
    </div>
</div>
