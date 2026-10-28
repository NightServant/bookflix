@props(['books' => []])

{{--
    The landing page's opening: the title, one line on what Bookflix is, the two ways in, and a stage of 3D books picked at random from
    the popular catalog (`books`: up to seven of LandingShowcase's arrays). The stage is one fixed composition drawn in container units,
    so it scales as a whole: the front book (the three.js one on capable devices) and six CSS books behind it, turned towards it and
    dimmed by their distance. They drift gently; with reduced motion nothing moves. The title is plain text from the first byte (it is the LCP).
--}}
@php
    // x, y: the book's centre in the stage; w: its width in container units; tilt: yaw (positive shows the spine, so the books on the left face the centre);
    // roll: a little turn in the picture plane; dim: how far back it is (0 front, 1 black); z: stacking. Order: front, then nearest to farthest.
    $slots = [
        ['x' => '47%', 'y' => '56%', 'w' => 38, 'tilt' => '-12deg', 'roll' => '-1.5deg', 'dim' => 0, 'z' => 50, 'bob' => 1, 'dur' => '9s', 'delay' => '-3s'],
        ['x' => '17%', 'y' => '50%', 'w' => 27, 'tilt' => '32deg', 'roll' => '-6deg', 'dim' => 0.25, 'z' => 30, 'bob' => 1.2, 'dur' => '10s', 'delay' => '-6s'],
        ['x' => '80%', 'y' => '49%', 'w' => 28, 'tilt' => '-34deg', 'roll' => '5deg', 'dim' => 0.25, 'z' => 30, 'bob' => 1, 'dur' => '11s', 'delay' => '-1s'],
        ['x' => '30%', 'y' => '11%', 'w' => 17, 'tilt' => '24deg', 'roll' => '5deg', 'dim' => 0.55, 'z' => 20, 'bob' => 1.4, 'dur' => '12s', 'delay' => '-8s'],
        ['x' => '67%', 'y' => '8%', 'w' => 16, 'tilt' => '-26deg', 'roll' => '-7deg', 'dim' => 0.55, 'z' => 20, 'bob' => 1.3, 'dur' => '10.5s', 'delay' => '-4s'],
        ['x' => '5%', 'y' => '88%', 'w' => 17, 'tilt' => '36deg', 'roll' => '-12deg', 'dim' => 0.5, 'z' => 20, 'bob' => 1.1, 'dur' => '13s', 'delay' => '-2s'],
        ['x' => '93%', 'y' => '87%', 'w' => 17, 'tilt' => '-34deg', 'roll' => '11deg', 'dim' => 0.5, 'z' => 20, 'bob' => 1.2, 'dur' => '9.5s', 'delay' => '-7s'],
    ];
    $pictured = collect($books)->take(3)->pluck('title');
@endphp

<section data-section="hero" aria-labelledby="hero-title" class="relative isolate overflow-hidden pt-24 pb-12 lg:pt-28 lg:pb-16">
    <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
        <div class="landing-hero__light"></div>
    </div>

    <div class="mx-auto grid max-w-[100rem] items-center gap-x-10 gap-y-6 px-gutter lg:min-h-[calc(100svh-9rem)] lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] lg:grid-rows-[1fr_auto]">
        <div class="relative z-10 max-w-2xl lg:row-span-2">
            <p class="text-legal font-medium tracking-[0.18em] text-ink-subtle uppercase">{{ __('Comics · Manga · Novels · Webcomics') }}</p>

            <h1 id="hero-title" class="mt-5 text-[clamp(2.75rem,5.6vw,5.5rem)] leading-[1.02] font-black tracking-tight text-balance text-ink">
                {{ __('Your Library.') }}
                <span class="block text-ink-subtle">{{ __('Every Story.') }}</span>
            </h1>

            <p class="mt-6 max-w-xl text-lead font-normal text-ink-subtle text-pretty">
                {{ __('Bookflix keeps every comic, manga, novel and webcomic you own, read and love on one calm shelf, filed the way a library would.') }}
            </p>

            <div class="mt-9 flex flex-wrap items-center gap-3">
                <x-cta :href="route('register')" icon:trailing="arrow-right">{{ __('Get started') }}</x-cta>
                <x-cta variant="secondary" :href="route('login')" icon="arrow-right-end-on-rectangle">{{ __('Sign in') }}</x-cta>
            </div>
        </div>

        @if (count($books))
            <div class="relative max-lg:left-1/2 max-lg:w-[min(140%,46rem)] max-lg:-translate-x-1/2 lg:col-start-2 lg:row-start-1 lg:-me-gutter lg:self-center">
                <div class="landing-stage" aria-hidden="true">
                    <svg class="landing-stage__rings" viewBox="0 0 100 88" preserveAspectRatio="none" focusable="false">
                        <ellipse cx="47" cy="56" rx="43" ry="19" transform="rotate(-12 47 56)" />
                        <ellipse cx="47" cy="56" rx="56" ry="30" transform="rotate(-12 47 56)" />
                    </svg>
                    <div class="landing-stage__glow"></div>

                    @foreach ($slots as $index => $slot)
                        @php($book = $books[$index] ?? null)
                        @if ($book)
                            <div
                                @class(['landing-book', 'max-sm:hidden' => $index >= 5])
                                style="--x: {{ $slot['x'] }}; --y: {{ $slot['y'] }}; --z: {{ $slot['z'] }}; --i: {{ $index }}; --dim: {{ $slot['dim'] }}; --roll: {{ $slot['roll'] }}; --bob: {{ $slot['bob'] }}; --dur: {{ $slot['dur'] }}; --delay: {{ $slot['delay'] }};"
                            >
                                <div class="landing-book__float">
                                    <x-book-3d
                                        :src="$book['cover']"
                                        :title="$book['title']"
                                        :tilt="$slot['tilt']"
                                        :scene="$index === 0"
                                        :scene-src="$book['scene']"
                                        :interactive="$index !== 0"
                                        style="--w: {{ $slot['w'] }}cqw"
                                    />
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>

            </div>

            @if ($pictured->isNotEmpty())
                <p class="truncate text-center text-legal text-ink-muted lg:col-start-2 lg:row-start-2 lg:text-end">{{ __('Popular right now: :titles', ['titles' => $pictured->join(', ', __(' and '))]) }}</p>
            @endif
        @endif
    </div>
</section>
