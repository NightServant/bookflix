@props(['books' => []])

{{--
    The landing page's opening: a centred eyebrow, title and one line on what Bookflix is, with a stage of 3D books picked at random
    from the popular catalog (`books`: up to seven of LandingShowcase's arrays). There are no buttons here: the header carries
    "Sign in" and "Get started". Where the books stand is all CSS (resources/css/landing.css, keyed on `data-slot`): a bowl that rises
    on either side of the text on a wide screen, a compact fan under it on a smaller one. Slot 0 is the front book (the three.js one on
    capable devices), then the rest nearest to farthest, each dimmed (and softened) by its distance. They drift gently; with reduced
    motion nothing moves. The title is plain text from the first byte (it is the LCP).
--}}
@php
    // dim: how far back a book is (0 front, 1 black); the rest is the idle drift: bob (strength), dur and delay (so no two move together).
    $slots = [
        ['dim' => 0, 'bob' => 1, 'dur' => '9s', 'delay' => '-3s'],
        ['dim' => 0.2, 'bob' => 1.2, 'dur' => '10s', 'delay' => '-6s'],
        ['dim' => 0.2, 'bob' => 1, 'dur' => '11s', 'delay' => '-1s'],
        ['dim' => 0.45, 'bob' => 1.4, 'dur' => '12s', 'delay' => '-8s'],
        ['dim' => 0.45, 'bob' => 1.3, 'dur' => '10.5s', 'delay' => '-4s'],
        ['dim' => 0.6, 'bob' => 1.1, 'dur' => '13s', 'delay' => '-2s'],
        ['dim' => 0.6, 'bob' => 1.2, 'dur' => '9.5s', 'delay' => '-7s'],
    ];
    // The caption names three of the books a phone shows too, and only titles short enough to stay on a line.
    $pictured = collect($books)->take(5)->pluck('title')->filter(fn (string $title): bool => mb_strlen($title) <= 28)->take(3)->values();
@endphp

<section data-section="hero" aria-labelledby="hero-title" class="landing-hero relative isolate overflow-hidden">
    <div class="landing-hero__inner mx-auto">
        <div class="landing-hero__copy relative z-10 mx-auto text-center">
            <p class="text-legal font-medium tracking-[0.18em] text-balance text-ink-subtle uppercase">{{ __('Comics · Manga · Novels · Webcomics') }}</p>

            <h1 id="hero-title" class="mt-5 text-[clamp(2.75rem,5.6vw,5.5rem)] leading-[1.02] font-black tracking-tight text-balance text-ink">
                {{ __('Your Library.') }}
                <span class="block text-ink-subtle">{{ __('Every Story.') }}</span>
            </h1>

            <p class="mx-auto mt-6 max-w-xl text-lead font-normal text-ink-subtle text-balance">
                {{ __('Bookflix keeps every comic, manga, novel and webcomic you own, read and love on one calm shelf, filed the way a library would.') }}
            </p>
        </div>

        @if (count($books))
            <div class="landing-stage" aria-hidden="true">
                @foreach ($slots as $index => $slot)
                    @php($book = $books[$index] ?? null)
                    @if ($book)
                        <div
                            data-slot="{{ $index }}"
                            @class(['landing-book', 'max-lg:hidden' => $index >= 5])
                            style="--i: {{ $index }}; --dim: {{ $slot['dim'] }}; --bob: {{ $slot['bob'] }}; --dur: {{ $slot['dur'] }}; --delay: {{ $slot['delay'] }};"
                        >
                            <div class="landing-book__float">
                                <x-book-3d
                                    :src="$book['cover']"
                                    :title="$book['title']"
                                    :scene="$index === 0"
                                    :scene-src="$book['scene']"
                                    :interactive="$index !== 0"
                                    style="--w: var(--bw); --tilt: var(--yaw)"
                                />
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>

            @if ($pictured->isNotEmpty())
                <p class="landing-hero__caption relative z-10 text-center text-legal text-balance text-ink-muted">{{ __('Popular right now: :titles', ['titles' => $pictured->join(', ', __(' and '))]) }}</p>
            @endif
        @endif
    </div>
</section>
