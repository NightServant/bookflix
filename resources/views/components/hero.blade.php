@props([
    'slides' => null,
    'media' => null,
    'size' => 'L',
    'label' => null,
    'bleed' => false,
])

{{--
    The featured-story carousel: the current story's cover as the backdrop, its eyebrow, title, blurb and actions on the
    left. On a desktop the right is a centrepiece: the current story as a 3D book (<x-book-3d size="xl" scene>, upgraded to three.js
    on capable devices, which turns to the next cover on a slide change) with the next two stories as smaller CSS books fanned behind
    it. On a phone the next three stories are cards under the text. Pass `:slides` (Media list) or one `:media`.
    `size` is L|M|S (S has no cards). `bleed` makes it a band that spans the whole page (in a `flush` layout) with its content
    on the header's gutter; otherwise it is a rounded card inside the page's container. There is no autoplay. The `cta` slot replaces the two per-slide buttons.
    "Add to Library" dispatches a `library-add` event with the media id; it renders only once the Phase 5 `library.store` route exists.
    Eager-load `mediaType` and `genres` on fetched media (the eyebrow reads them).
--}}
@php
    $items = collect($slides ?? [$media])->filter()->values();
    $count = $items->count();
    $total = str_pad((string) $count, 2, '0', STR_PAD_LEFT);
    $compact = strtoupper($size) === 'S';

    [$height, $titleSize] = match (strtoupper($size)) {
        'S' => ['min-h-56 lg:min-h-64', 'text-lead'],
        'M' => ['min-h-80 lg:min-h-96', 'text-section'],
        default => ['min-h-112 lg:min-h-128', 'text-section md:text-hero'],
    };

    $cards = $items->map(fn ($slide) => [
        'id' => $slide->id,
        'title' => $slide->title,
        'cover' => $slide->cover_url,
        'scene' => $slide->cover_url ? route('covers.show', $slide) : null,
    ])->all();
    $first = $items->first();
    // Ghost buttons: a bright icon in a 44px hit area, no fill. Hover draws a faint ring; they never get a solid background.
    $control = 'focus-ring inline-flex size-11 items-center justify-center rounded-control text-ink hover:ring-1 hover:ring-ink/50 disabled:text-ink-muted';
@endphp

@if ($count > 0)
    <section
        aria-roledescription="carousel"
        aria-label="{{ $label ?? __('Featured stories') }}"
        x-data="hero(@js($cards))"
        @keydown.arrow-left="prev()"
        @keydown.arrow-right="next()"
        {{ $attributes->class(['relative overflow-hidden bg-surface-deep', 'rounded-card' => ! $bleed]) }}
    >
        <div class="absolute inset-0" aria-hidden="true">
            @foreach ($items as $index => $slide)
                @if ($slide->cover_url)
                    <img
                        src="{{ $slide->cover_url }}"
                        alt=""
                        width="1280"
                        height="720"
                        decoding="async"
                        data-backdrop="{{ $index }}"
                        wire:key="hero-backdrop-{{ $slide->id }}"
                        x-show="current === {{ $index }}"
                        x-motion-crossfade="fade"
                        @if ($index > 0) style="display: none" @endif
                        @if ($index === 0) fetchpriority="high" @else loading="lazy" @endif
                        class="absolute inset-0 size-full scale-110 object-cover blur-md lg:inset-y-0 lg:left-auto lg:right-0 lg:w-3/5 lg:scale-100 lg:object-[50%_30%] lg:blur-sm"
                    >
                @endif
            @endforeach
            <div class="absolute inset-x-0 bottom-0 h-3/5 bg-linear-to-t from-canvas/90 via-canvas/50 to-transparent lg:h-1/4 lg:from-canvas lg:via-canvas/40"></div>
            <div class="absolute inset-y-0 right-0 hidden w-3/5 bg-canvas/40 lg:block"></div>
            <div data-hero-scrim class="absolute inset-0 bg-linear-to-t from-canvas via-canvas/80 to-canvas/60 lg:bg-linear-to-r lg:from-canvas lg:via-canvas/85 lg:to-transparent"></div>
        </div>

        <div data-hero-content class="relative flex flex-col justify-end gap-8 {{ $bleed ? 'px-gutter py-8 lg:py-12' : 'p-4 sm:p-8' }} {{ $height }} {{ $compact ? 'lg:flex-row lg:items-center lg:justify-between' : 'lg:grid lg:grid-cols-[minmax(0,1fr)_30rem] lg:grid-rows-[1fr_auto] lg:items-center lg:gap-x-8 lg:gap-y-0' }}">
            <div class="max-w-2xl lg:self-end" aria-live="polite">
                @foreach ($items as $index => $slide)
                    @php
                        $eyebrow = collect([$slide->mediaType->name, $slide->genres->first()?->name])->filter()->implode(' · ');
                        $words = preg_split('/\s+/u', trim($slide->title), -1, PREG_SPLIT_NO_EMPTY);
                    @endphp
                    <article
                        role="group"
                        aria-roledescription="slide"
                        aria-label="{{ __(':current of :total', ['current' => $index + 1, 'total' => $count]) }}"
                        data-slide="{{ $index }}"
                        wire:key="hero-slide-{{ $slide->id }}"
                        x-show="current === {{ $index }}"
                        @if ($index > 0) style="display: none" @endif
                    >
                        <p class="text-legal uppercase tracking-widest text-ink-subtle">{{ $eyebrow }}</p>
                        <h1 class="mt-1 tracking-tight {{ $titleSize }} text-ink">
                            @foreach ($words as $word)<span class="-mb-[0.12em] inline-block overflow-hidden pb-[0.12em] align-bottom"><span data-word class="inline-block">{{ $word }}</span></span> @endforeach
                        </h1>
                        @if ($slide->description)
                            <p class="mt-4 line-clamp-2 text-body text-ink-subtle">{{ $slide->description }}</p>
                        @endif
                        <div class="mt-6 flex flex-wrap gap-3">
                            @if (isset($cta) && $cta->isNotEmpty())
                                {{ $cta }}
                            @else
                                <x-cta icon="book-open" :href="route('stories.show', $slide->slug)" wire:navigate>{{ __('View story') }}</x-cta>
                                @if (Route::has('library.store'))
                                    <x-cta variant="secondary" icon="plus" @click="$dispatch('library-add', { id: {{ $slide->id }} })">{{ __('Add to Library') }}</x-cta>
                                @endif
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>

            @if ($count > 1)
                @unless ($compact)
                    <ul aria-label="{{ __('Up next') }}" x-edge-fade class="max-lg:edge-fade max-lg:[--fade-width:1.5rem] -mx-1 flex snap-x gap-3 overflow-x-auto p-1 [scrollbar-width:none] lg:hidden [&::-webkit-scrollbar]:hidden">
                        <template x-for="card in upcoming" :key="card.id">
                            <li :data-card="card.id" class="w-24 shrink-0 snap-start">
                                <button
                                    type="button"
                                    @click="show(card.index)"
                                    :aria-label="@js(__('Show :title')).replace(':title', card.title)"
                                    class="group focus-ring block w-full rounded-card text-start"
                                >
                                    <span class="block aspect-[2/3] overflow-hidden rounded-card bg-surface-card">
                                        <template x-if="!card.cover"><span x-text="card.title" aria-hidden="true" class="line-clamp-5 p-2 text-legal font-black leading-tight tracking-tight text-ink"></span></template>
                                        <img
                                            x-show="card.cover"
                                            :src="card.cover"
                                            alt=""
                                            width="128"
                                            height="192"
                                            loading="lazy"
                                            class="size-full object-cover"
                                        >
                                    </span>
                                    <span class="mt-2 line-clamp-2 text-legal text-ink" x-text="card.title"></span>
                                </button>
                            </li>
                        </template>
                    </ul>
                @endunless

                <div class="flex items-center gap-3 lg:mt-8 lg:self-start">
                    <div aria-hidden="true" class="h-0.5 w-16 bg-ink/50 sm:w-40">
                        <div
                            class="h-full origin-left scale-x-(--progress) bg-ink motion-safe:transition-transform motion-safe:duration-(--motion-base) motion-safe:ease-out-bookflix"
                            style="--progress: {{ round(1 / $count, 4) }}"
                            :style="{ '--progress': (current + 1) / count }"
                        ></div>
                    </div>
                    <p aria-hidden="true" class="tabular-nums"><span class="text-lead text-ink" x-text="String(current + 1).padStart(2, '0')">01</span> <span class="text-meta text-ink-subtle">/ {{ $total }}</span></p>
                    <div class="flex items-center">
                        <button type="button" @click="prev()" aria-label="{{ __('Previous slide') }}" class="{{ $control }}">
                            <flux:icon name="chevron-left" class="size-5" />
                        </button>
                        <button type="button" @click="next()" data-hero-next aria-label="{{ __('Next slide') }}" class="{{ $control }}">
                            <flux:icon name="chevron-right" class="size-5" />
                        </button>
                    </div>
                </div>
            @endif

            @unless ($compact)
                {{-- The centrepiece (desktop): the active story as a 3D book, the next two fanned behind it as smaller CSS books. --}}
                <div data-hero-books class="relative hidden h-[27rem] lg:col-start-2 lg:row-span-2 lg:row-start-1 lg:block">
                    @if ($count > 1)
                        @foreach ([['size' => 'lg', 'class' => 'left-[12.5rem] top-[3.25rem] z-[2]'], ['size' => 'md', 'class' => 'left-[20.5rem] top-[6.5rem] z-[1]']] as $slot => $fan)
                            <button
                                type="button"
                                data-fan="{{ $slot }}"
                                x-show="upcoming[{{ $slot }}]"
                                @click="show(upcoming[{{ $slot }}].index)"
                                :aria-label="@js(__('Show :title')).replace(':title', upcoming[{{ $slot }}]?.title ?? '')"
                                class="group focus-ring absolute {{ $fan['class'] }} rounded-card brightness-[.62] saturate-[.8] motion-safe:transition-[filter,translate] motion-safe:duration-(--motion-base) motion-safe:ease-out-bookflix hover:-translate-y-2 hover:brightness-90 focus-visible:brightness-90"
                            >
                                <x-book-3d :src="$items->get($slot + 1)?->cover_url ?? $first->cover_url" :title="$items->get($slot + 1)?->title" :size="$fan['size']" tilt="28deg" :bind="'upcoming['.$slot.']?.cover ?? \'\''" />
                            </button>
                        @endforeach
                    @endif

                    <div class="absolute left-2 top-0 z-10">
                        <x-book-3d :media="$first" size="xl" tilt="28deg" scene bind="active.cover ?? ''" :title="$first->title" />
                    </div>
                </div>
            @endunless
        </div>
    </section>
@endif
