@props(['covers' => []])

{{--
    The first of the two argument sections: the state of reading today, in type. One oversized statement that trails off (ink, then
    gray, then dimmer), three plain rows under it (no cards, no icons) and, beside them, a heap of covers spilled across the page, each
    tagged with the place it lives. Calm on purpose: it only describes, the next section answers. Decorative heap (aria-hidden); the
    words say the same thing.
--}}
@php
    $rows = [
        [__('Too many places.'), __('Every format has its own app, its own account and its own idea of what you have read. Switching means starting over.')],
        [__('Nothing holds it all.'), __('Comics, manga, novels and webcomics rarely share a shelf. No single library gathers all four, so a collection never adds up.')],
        [__('Discovery by luck.'), __('Finding the next story means searching five sites and trusting whichever list shouted loudest. Nothing tells you what readers actually love.')],
    ];

    // x, y: centre in the heap (%); w: width (container units); r: how far it has been knocked off true; tag: where this one lives.
    $heap = [
        ['x' => 15, 'y' => 17, 'w' => 27, 'r' => -10, 'tag' => __('a manga app'), 'z' => 2, 'end' => false],
        ['x' => 51, 'y' => 11, 'w' => 25, 'r' => 6, 'tag' => __('a webcomic site'), 'z' => 1, 'end' => false],
        ['x' => 86, 'y' => 22, 'w' => 24, 'r' => 13, 'tag' => __('browser bookmarks'), 'z' => 2, 'end' => true],
        ['x' => 33, 'y' => 47, 'w' => 28, 'r' => -4, 'tag' => __('a notes file'), 'z' => 4, 'end' => false],
        ['x' => 71, 'y' => 54, 'w' => 26, 'r' => -9, 'tag' => __('an e-reader'), 'z' => 3, 'end' => true],
        ['x' => 13, 'y' => 80, 'w' => 24, 'r' => 7, 'tag' => __('a spreadsheet'), 'z' => 2, 'end' => false],
        ['x' => 50, 'y' => 85, 'w' => 25, 'r' => 4, 'tag' => __('a forum thread'), 'z' => 1, 'end' => false],
    ];
@endphp

<section data-section="scattered" aria-labelledby="scattered-title" class="overflow-x-clip border-y border-hairline bg-surface-deep py-20 lg:py-32">
    <div class="mx-auto max-w-[100rem] px-gutter">
        <h2 id="scattered-title" class="landing-statement max-w-[22ch] lg:max-w-[20ch]">
            <span>{{ __('One story lives in an app.') }}</span>
            <span class="text-ink-subtle">{{ __('The next sits on a website.') }}</span>
            <span class="text-ink-muted">{{ __('The rest are bookmarks you stopped trusting.') }}</span>
        </h2>

        <div class="mt-14 grid gap-x-16 gap-y-12 lg:mt-20 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] lg:items-center">
            <ol x-data x-motion-reveal data-stagger="110" class="border-b border-hairline">
                @foreach ($rows as $index => [$lead, $body])
                    <li class="grid gap-x-6 gap-y-3 border-t border-hairline py-7 sm:grid-cols-[3.5rem_minmax(0,1fr)] lg:py-9">
                        <span class="text-meta tabular-nums text-ink-muted" aria-hidden="true">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <div>
                            <h3 class="text-[clamp(1.5rem,2.5vw,2.25rem)] leading-tight font-bold tracking-tight text-ink">{{ $lead }}</h3>
                            <p class="mt-3 max-w-md text-body text-ink-subtle text-pretty">{{ $body }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>

            @if (count($covers))
                <div class="landing-heap max-lg:order-first" aria-hidden="true">
                    @foreach ($heap as $index => $spot)
                        @php($cover = $covers[$index] ?? null)
                        @if ($cover)
                            <div class="landing-heap__item" style="--x: {{ $spot['x'] }}%; --y: {{ $spot['y'] }}%; --w: {{ $spot['w'] }}cqw; --r: {{ $spot['r'] }}deg; --z: {{ $spot['z'] }}; --i: {{ $index }};">
                                <img src="{{ $cover['cover'] }}" width="160" height="240" alt="" loading="lazy" decoding="async" draggable="false">
                            </div>
                            <span @class(['landing-heap__tag', 'landing-heap__tag--end' => $spot['end']]) style="--x: {{ $spot['x'] }}%; --y: {{ $spot['y'] }}%; --w: {{ $spot['w'] }}cqw; --r: {{ $spot['r'] }}deg;">{{ $spot['tag'] }}</span>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>
