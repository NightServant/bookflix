{{--
    Five questions about Bookflix as full-width stacked buttons on the input gray, large type, a plus on the right that turns into a
    close, and the answer in the same gray just below with a hairline of canvas between (the pattern of big-streaming-service FAQs,
    drawn here in Bookflix's own tokens). Each question is a real button (`aria-expanded`, `aria-controls`) over a `region` named by it;
    one answer is open at a time; Up, Down, Home and End move between the questions (resources/js/landing.js, `landingFaq`). Under it, the
    sign-up box: an email the sign-up form will already have filled in (POST /start keeps it out of the URL).
--}}
@php
    $faqs = [
        [__('What is Bookflix?'), __('Bookflix is a library for everything you read: comics, manga, novels and webcomics in one place, instead of one app or site for each. It pairs a catalog of stories, with their covers, creators, genres and themes, with a library filed the way a real library is filed.')],
        [__('What kinds of stories can I find?'), __('Manga, manhwa and manhua, comics and graphic novels, light novels and novels, webcomics and picture books. Every story has its own page with a cover, a description, who made it, what it is about and what to read next, and the most popular ones are easy to find.')],
        [__('What does “Dewey-inspired” mean?'), __('Libraries file books by class, and so does Bookflix. A story sits in a class from broad ones such as Literature (800) or Arts & Play (700) down to narrow ones such as Japanese Comics (741.52). You can walk the shelf by subject, or search and filter by genre and theme.')],
        [__('Where does the catalog come from, and what counts as popular?'), __('From open catalogs and community databases such as AniList, MangaDex, MangaUpdates and Open Library, and every story keeps its source. Popularity blends their signals, such as ratings and follows, ranking each against its own source so a large site never drowns a small one. The Top 10 is simply the highest scores.')],
        [__('Is my reading private?'), __('Yes, by default. Your profile and your library start out private, and you choose in your settings whether anyone else can see them. Suggestions that learn from what you read can be switched off at any time.')],
    ];
@endphp

<section id="faq" data-section="faq" aria-labelledby="faq-title" class="scroll-mt-16 py-20 lg:py-28">
    <noscript><style>.landing-faq__panel { grid-template-rows: 1fr; visibility: visible; }</style></noscript>

    <div class="px-gutter"><div class="mx-auto max-w-[52rem]">
        <h2 id="faq-title" class="landing-h2 text-center">{{ __('Frequently Asked Questions') }}</h2>

        <div x-data="landingFaq" @keydown="move($event)" class="mt-10 grid gap-2 lg:mt-12">
            @foreach ($faqs as $index => [$question, $answer])
                <div data-faq-item class="landing-faq__item">
                    <h3>
                        <button
                            type="button"
                            data-faq-button
                            id="faq-question-{{ $index }}"
                            aria-controls="faq-answer-{{ $index }}"
                            aria-expanded="false"
                            :aria-expanded="(open === {{ $index }}).toString()"
                            @click="toggle({{ $index }})"
                            class="landing-faq__button focus-ring"
                        >
                            <span>{{ $question }}</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true" class="landing-faq__icon" :class="open === {{ $index }} && 'landing-faq__icon--open'">
                                <path d="M12 3v18M3 12h18" />
                            </svg>
                        </button>
                    </h3>
                    <div
                        id="faq-answer-{{ $index }}"
                        role="region"
                        aria-labelledby="faq-question-{{ $index }}"
                        class="landing-faq__panel"
                        :data-open="open === {{ $index }} ? '' : null"
                    >
                        <div>
                            <p>{{ $answer }}</p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <form id="start" method="POST" action="{{ route('landing.start') }}" class="mt-14 scroll-mt-24 text-center lg:mt-20" novalidate>
            @csrf
            <p class="mx-auto max-w-2xl text-lead text-ink text-balance">{{ __('Ready to build your library? Enter your email to create your account.') }}</p>

            <div class="mx-auto mt-6 flex max-w-2xl flex-col gap-3 text-start sm:flex-row sm:items-start">
                <div class="min-w-0 flex-1">
                    <label for="start-email" class="sr-only">{{ __('Email address') }}</label>
                    <input
                        id="start-email"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        required
                        autocomplete="email"
                        placeholder="{{ __('Email address') }}"
                        @error('email') aria-invalid="true" aria-describedby="start-error" @enderror
                        class="focus-ring block min-h-14 w-full rounded-control border border-border-input bg-canvas/60 px-4 text-lead font-normal text-ink placeholder:text-ink-subtle aria-invalid:border-red-400"
                    >
                    @error('email')
                        <p id="start-error" role="alert" class="mt-2 text-meta text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <x-cta type="submit" icon:trailing="arrow-right" class="min-h-14! px-6! text-lead!">{{ __('Get started') }}</x-cta>
            </div>
        </form>
    </div></div>
</section>
