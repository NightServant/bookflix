@props(['covers' => [], 'topTen' => []])

{{--
    The second argument section: what Bookflix does, as a walkthrough. Four short numbered statements on the left; on the right a live
    preview of the product, built from the real components (CSS books, chips) and the real catalog (covers, titles, Dewey classes), that
    changes with each statement. Wide screens with motion allowed pin the preview and swap it as the statements scroll past
    (resources/js/landing.js, `landingWalk`); on a phone, with reduced motion or without the script, each statement sits above its own
    preview and every state is on the page at once. The previews are decorative (aria-hidden): the statements carry the meaning.
--}}
@php
    $steps = [
        [__('Everything you read, on one shelf.'), __('Comics, manga, novels and webcomics sit in a single library instead of four apps, so what you have read, and what you mean to read, is in one place.')],
        [__('Filed the way a library files it.'), __('A Dewey-inspired shelf gives every story a class. Walk down from Arts & Play to Japanese Comics, or from Literature to Japanese Literature, the way you would walk the stacks.')],
        [__('Popular, and shown to be.'), __('Popularity signals from across the catalog rank what is worth your evening, so the top of a list means something and a trending story is easy to find.')],
        [__('Genres and themes, all the way down.'), __('From Slice Of Life to Time Travel, from Revenge to Coming Of Age: find a story by what it is about, not only by what it is called.')],
    ];
@endphp

<section id="together" data-section="together" aria-labelledby="together-title" class="scroll-mt-16 py-20 lg:py-32">
    <div class="mx-auto max-w-[100rem] px-gutter">
        <header class="max-w-3xl">
            <p class="text-legal font-medium tracking-[0.18em] text-ink-subtle uppercase">{{ __('One library') }}</p>
            <h2 id="together-title" class="landing-h2 mt-4">{{ __('Shelved the way libraries have always done it.') }}</h2>
        </header>

        <div x-data="landingWalk" class="landing-walk mt-12 lg:mt-20">
            <div class="landing-steps">
                @foreach ($steps as $index => [$title, $body])
                    <article data-step class="landing-step" style="--n: {{ $index + 1 }}">
                        <p class="text-meta tabular-nums text-ink-muted" aria-hidden="true">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</p>
                        <h3 class="mt-3 text-[clamp(1.75rem,3vw,2.75rem)] leading-[1.1] font-bold tracking-tight text-balance text-ink">{{ $title }}</h3>
                        <p class="mt-4 max-w-lg text-lead font-normal text-ink-subtle text-pretty">{{ $body }}</p>
                    </article>
                @endforeach
            </div>

            <div class="landing-previews" aria-hidden="true">
                <div data-preview class="landing-preview" style="--n: 1"><x-landing.previews.shelf :covers="$covers" /></div>
                <div data-preview class="landing-preview" style="--n: 2"><x-landing.previews.dewey :covers="array_slice($covers, 2)" /></div>
                <div data-preview class="landing-preview" style="--n: 3"><x-landing.previews.ranked :items="$topTen" /></div>
                <div data-preview class="landing-preview" style="--n: 4"><x-landing.previews.genres :covers="array_slice($covers, 4)" /></div>
            </div>
        </div>
    </div>
</section>
