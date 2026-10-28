@props(['covers' => []])

{{--
    The last word before the footer: a heading, one line, the two ways in, and under them a row of real books standing upright on a
    shelf, the tidy answer to the hero's drifting ones. Decorative books (aria-hidden); a phone shows five of them.
--}}
@php
    // Width of each book in container units: they differ a little, as real spines do.
    $widths = [9.5, 10.5, 9, 11, 10, 9.5, 10.5, 9, 10];
@endphp

<section data-section="closing" aria-labelledby="closing-title" class="overflow-hidden border-t border-hairline pt-20 lg:pt-32">
    <div class="px-gutter text-center">
        <h2 id="closing-title" class="mx-auto max-w-[16ch] text-[clamp(2.5rem,6.4vw,6rem)] leading-[1.02] font-black tracking-tight text-balance text-ink">{{ __('Start your library today.') }}</h2>
        <p class="mx-auto mt-6 max-w-xl text-lead font-normal text-ink-subtle text-pretty">{{ __('Create an account, set up your reading profile and let Bookflix keep every story you love in one place.') }}</p>

        <div class="mt-9 flex flex-wrap items-center justify-center gap-3">
            <x-cta :href="route('register')" icon:trailing="arrow-right">{{ __('Get started') }}</x-cta>
            <x-cta variant="secondary" :href="route('login')" icon="arrow-right-end-on-rectangle">{{ __('Sign in') }}</x-cta>
        </div>
    </div>

    @if (count($covers))
        <div class="landing-standing mt-16 lg:mt-24" aria-hidden="true">
            @foreach (array_slice($covers, 0, 9) as $index => $cover)
                <div @class(['max-md:hidden' => $index >= 5])>
                    <x-book-3d :src="$cover['cover']" :title="$cover['title']" tilt="-14deg" lazy style="--w: {{ $widths[$index] }}cqw" />
                </div>
            @endforeach
        </div>
    @endif
</section>
