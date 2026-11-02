@props(['items' => []])

{{--
    "Top 10": the ten most popular stories with a cover, as flat posters in a still grid of two rows of five (two to a row on a phone),
    inside the same container as the heading so its edges line up with the heading text. Each poster stands in front of a huge outlined
    numeral (dark fill, light-gray stroke), its rank; "10" is drawn smaller so both digits show. Nothing moves, so nothing needs pausing.
    The posters are not links: the page is for people who have no account yet.
--}}
@if (count($items))
    <section
        id="top-10"
        data-section="top-ten"
        aria-labelledby="top-ten-title"
        class="scroll-mt-16 py-16 lg:py-24"
    >
        <div class="mx-auto max-w-[100rem] px-gutter">
            <div class="max-w-2xl">
                <h2 id="top-ten-title" class="landing-h2">{{ __('Top 10 on Bookflix today') }}</h2>
                <p class="mt-3 text-body text-ink-subtle text-pretty">{{ __('The stories readers are reaching for right now, ranked by how popular they are across the catalog.') }}</p>
            </div>

            <ol role="list" aria-label="{{ __('Top 10 stories') }}" class="landing-top mt-10 lg:mt-12">
                @foreach ($items as $index => $item)
                    <li class="landing-top__item">
                        <span class="sr-only">{{ __('Number :rank: :title', ['rank' => $index + 1, 'title' => $item['title']]) }}</span>
                        <span @class(['landing-top__rank', 'landing-top__rank--two' => $index + 1 > 9]) aria-hidden="true">{{ $index + 1 }}</span>
                        <span class="landing-top__poster" aria-hidden="true">
                            <img src="{{ $item['cover'] }}" width="176" height="264" loading="lazy" decoding="async" draggable="false" alt="">
                            <span class="landing-top__title">{{ $item['title'] }}</span>
                        </span>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>
@endif
