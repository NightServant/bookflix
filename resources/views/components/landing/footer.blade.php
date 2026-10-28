{{--
    The landing footer: a statement and the link columns, the data attribution, the legal line, and a wordmark as wide as the page,
    cropped by the bottom edge. The wordmark is an SVG whose text is stretched to the exact width (`textLength`), so it fits any
    font and any screen; it is decoration (aria-hidden), the name is already in the header and the legal line.
--}}
@php
    $columns = [
        __('Explore') => [
            ['#top-10', __('Top 10')],
            ['#together', __('Why Bookflix')],
            ['#faq', __('Questions')],
        ],
        __('Account') => [
            [route('register'), __('Create an account')],
            [route('login'), __('Sign in')],
        ],
        __('Data from') => [
            ['https://anilist.co', 'AniList'],
            ['https://mangadex.org', 'MangaDex'],
            ['https://www.mangaupdates.com', 'MangaUpdates'],
            ['https://openlibrary.org', 'Open Library'],
        ],
    ];
@endphp

<footer class="overflow-hidden border-t border-hairline bg-canvas">
    <div class="mx-auto grid max-w-[100rem] gap-x-16 gap-y-12 px-gutter pt-16 pb-10 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] lg:pt-24">
        <div>
            <x-app-logo href="{{ route('landing') }}" />
            <p class="mt-6 max-w-md text-[clamp(1.5rem,2.6vw,2.25rem)] leading-[1.12] font-bold tracking-tight text-balance text-ink">{{ __('Every story you mean to read, on one calm shelf.') }}</p>
        </div>

        <nav aria-label="{{ __('Footer') }}" class="grid grid-cols-2 gap-x-8 gap-y-10 sm:grid-cols-3">
            @foreach ($columns as $heading => $links)
                <div>
                    <h2 class="text-legal font-medium tracking-[0.18em] text-ink-subtle uppercase">{{ $heading }}</h2>
                    <ul class="mt-3">
                        @foreach ($links as [$href, $label])
                            <li><a href="{{ $href }}" class="focus-ring inline-flex min-h-11 items-center rounded-control text-body text-ink underline-offset-4 hover:underline">{{ $label }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>

        <div class="flex flex-col justify-between gap-6 border-t border-hairline pt-8 text-meta text-ink-subtle lg:col-span-2 lg:flex-row lg:items-end">
            <p class="max-w-2xl text-pretty">{{ __('Data from AniList, MangaDex, MangaUpdates, Open Library and other open catalogs. Covers, titles and descriptions belong to their owners, and Bookflix is not affiliated with any of them.') }}</p>
            <p class="flex flex-wrap items-center gap-x-6 gap-y-2">
                <span>&copy; {{ now()->year }} {{ config('app.name', 'Bookflix') }}. {{ __('All rights reserved.') }}</span>
                <a href="#main" class="focus-ring inline-flex min-h-11 items-center gap-2 rounded-control text-ink hover:underline">{{ __('Back to top') }}<flux:icon name="arrow-up" variant="outline" class="size-4" /></a>
            </p>
        </div>
    </div>

    <svg viewBox="0 0 1000 190" class="landing-wordmark" aria-hidden="true" focusable="false" preserveAspectRatio="xMidYMax meet">
        <text x="10" y="168" textLength="980" lengthAdjust="spacingAndGlyphs">{{ mb_strtoupper(config('app.name', 'Bookflix')) }}</text>
    </svg>
</footer>
