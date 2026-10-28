@props(['items' => []])

{{--
    "Top 10": the ten most popular stories with a cover as flat posters on a slow, endless marquee. Each poster stands in front of a huge
    outlined numeral (dark fill, light-gray stroke), its rank. The track holds the list twice so the loop has no seam; the second copy is
    aria-hidden. Hover or keyboard focus on the section pauses it, and so does the pause button (WCAG 2.2.2). With reduced motion nothing
    moves: the second copy is gone and the list scrolls by hand. The posters are not links: the page is for people who have no account yet.
--}}
@if (count($items))
    <section
        id="top-10"
        data-section="top-ten"
        aria-labelledby="top-ten-title"
        x-data="{ paused: false, reduced: window.matchMedia('(prefers-reduced-motion: reduce)').matches }"
        x-init="window.matchMedia('(prefers-reduced-motion: reduce)').addEventListener('change', (event) => reduced = event.matches)"
        class="scroll-mt-16 py-16 lg:py-24"
    >
        <div class="mx-auto flex max-w-[100rem] flex-wrap items-end justify-between gap-x-8 gap-y-4 px-gutter">
            <div class="max-w-2xl">
                <h2 id="top-ten-title" class="landing-h2">{{ __('Top 10 on Bookflix today') }}</h2>
                <p class="mt-3 text-body text-ink-subtle text-pretty">{{ __('The stories readers are reaching for right now, ranked by how popular they are across the catalog.') }}</p>
            </div>

            <button
                type="button"
                @click="paused = !paused"
                :aria-pressed="paused.toString()"
                aria-pressed="false"
                class="focus-ring inline-flex min-h-11 items-center gap-2.5 rounded-control bg-surface-input px-4 text-meta font-medium text-ink hover:bg-hairline motion-reduce:hidden"
            >
                <flux:icon name="pause" variant="outline" class="size-5" x-show="!paused" />
                <flux:icon name="play" variant="outline" class="size-5" x-show="paused" style="display: none" />
                {{ __('Pause scrolling') }}
            </button>
        </div>

        <div class="landing-marquee mt-10 lg:mt-12" :data-paused="paused ? '' : null">
            <div class="landing-marquee__track">
                @foreach ([false, true] as $copy)
                    <ol
                        role="list"
                        @if ($copy)
                            aria-hidden="true"
                        @else
                            aria-label="{{ __('Top 10 stories') }}"
                            :tabindex="reduced ? 0 : null"
                        @endif
                        class="landing-top"
                    >
                        @foreach ($items as $index => $item)
                            <li class="landing-top__item">
                                @unless ($copy)
                                    <span class="sr-only">{{ __('Number :rank: :title', ['rank' => $index + 1, 'title' => $item['title']]) }}</span>
                                @endunless
                                <span class="landing-top__rank" aria-hidden="true">{{ $index + 1 }}</span>
                                <span class="landing-top__poster" aria-hidden="true">
                                    <img src="{{ $item['cover'] }}" width="176" height="264" loading="lazy" decoding="async" draggable="false" alt="">
                                    <span class="landing-top__title">{{ $item['title'] }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ol>
                @endforeach
            </div>
        </div>
    </section>
@endif
