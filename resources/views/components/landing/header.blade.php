@props(['hasTopTen' => false])

@php
    // The in-page links, in the bar from 768px up and in the drawer below it. Top 10 only when the band renders (see welcome.blade.php).
    $links = [...($hasTopTen ? [['#top-10', __('Top 10')]] : []), ['#together', __('Why Bookflix')], ['#faq', __('FAQ')]];
@endphp

{{--
    The landing page's top bar: the logo, a few in-page links on a wide screen, and the two ways in. Transparent over the hero, and a
    blurred canvas with a hairline once the page has scrolled. The hero has no buttons of its own, so the bar keeps both ways in: "Sign in" as plain text
    beside "Get started" from 640px up; below that the bar holds the logo, "Get started" and the menu button, and "Sign in" lives in the drawer (a phone bar has no room for both).
    Below 768px a menu button opens a drawer (self-contained here: the markup, the focus trap and the scroll lock) with the in-page links and both ways in.
    The drawer sits beside the header, not inside it: the header's blurred background would make it the containing block of a fixed child.
--}}
<div x-data="landingMenu" @keydown.escape.window="open && close()">
    <header
        x-data="{ scrolled: false }"
        x-init="scrolled = window.scrollY > 8"
        @scroll.window.passive="scrolled = window.scrollY > 8"
        class="fixed inset-x-0 top-0 z-40 border-b border-transparent motion-safe:transition-[background-color,border-color] motion-safe:duration-(--motion-base)"
        :class="scrolled && 'border-hairline! bg-canvas/80 backdrop-blur-md'"
    >
        <div class="flex min-h-16 items-center justify-between gap-4 px-gutter">
            <x-app-logo href="{{ route('landing') }}" data-test="logo" />

            <nav aria-label="{{ __('On this page') }}" class="max-md:hidden">
                <ul class="flex items-center gap-1">
                    @foreach ($links as [$href, $label])
                        <li><a href="{{ $href }}" class="focus-ring inline-flex min-h-11 items-center rounded-control px-3 text-meta text-ink-subtle transition-colors hover:text-ink">{{ $label }}</a></li>
                    @endforeach
                </ul>
            </nav>

            <div class="flex items-center gap-3 max-sm:gap-2">
                <x-cta variant="secondary" :href="route('login')" class="max-sm:hidden">{{ __('Sign in') }}</x-cta>
                <x-cta :href="route('register')" class="max-[22rem]:px-3!">{{ __('Get started') }}</x-cta>

                <button
                    type="button"
                    data-test="landing-menu-button"
                    @click="toggle()"
                    :aria-expanded="open.toString()"
                    aria-controls="landing-menu"
                    aria-label="{{ __('Menu') }}"
                    class="focus-ring -me-2 inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-ink md:hidden"
                >
                    <flux:icon name="bars-2" variant="outline" class="size-6" />
                </button>
            </div>
        </div>
    </header>

    {{-- The drawer: the in-page links, Sign in and the one primary action. The trap keeps focus inside, makes the rest of the page inert and locks scrolling while it is open. --}}
    <div
        id="landing-menu"
        x-show="open"
        x-cloak
        style="display: none"
        x-trap.inert.noscroll.noreturn="open"
        x-transition:enter="transition-opacity duration-(--motion-base) ease-out motion-reduce:duration-0"
        x-transition:enter-start="opacity-0"
        x-transition:leave="transition-opacity duration-(--motion-fast) ease-in motion-reduce:duration-0"
        x-transition:leave-end="opacity-0"
        role="dialog"
        aria-modal="true"
        aria-labelledby="landing-menu-title"
        class="fixed inset-0 z-50 md:hidden"
    >
        <div data-test="landing-menu-backdrop" class="absolute inset-0 bg-canvas/80" @click="close()"></div>

        <div
            x-show="open"
            x-transition:enter="transition-transform duration-(--motion-base) ease-out motion-reduce:duration-0"
            x-transition:enter-start="translate-x-full"
            x-transition:leave="transition-transform duration-(--motion-fast) ease-in motion-reduce:duration-0"
            x-transition:leave-end="translate-x-full"
            class="absolute inset-y-0 end-0 flex w-[min(20rem,100%)] flex-col gap-2 overflow-y-auto border-s border-hairline bg-surface-deep px-gutter pb-8"
        >
            <div class="flex min-h-16 items-center justify-between">
                <span id="landing-menu-title" class="text-lead text-ink">{{ __('Menu') }}</span>
                <button type="button" data-test="landing-menu-close" @click="close()" aria-label="{{ __('Close menu') }}" class="focus-ring -me-2 inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-ink">
                    <flux:icon name="x-mark" variant="outline" class="size-6" />
                </button>
            </div>

            <nav aria-label="{{ __('On this page') }}">
                <ul>
                    @foreach ($links as $index => [$href, $label])
                        <li>
                            <a href="{{ $href }}" @click="close(false)" @if ($index === 0) autofocus @endif class="focus-ring flex min-h-11 items-center rounded-control text-body text-ink">{{ $label }}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <div class="mt-4 flex flex-col gap-3 border-t border-hairline pt-6">
                <x-cta variant="secondary" :href="route('login')" class="w-full">{{ __('Sign in') }}</x-cta>
                <x-cta :href="route('register')" class="w-full">{{ __('Get started') }}</x-cta>
            </div>
        </div>
    </div>
</div>
