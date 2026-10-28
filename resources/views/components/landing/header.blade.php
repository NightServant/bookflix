{{--
    The landing page's top bar: the logo, a few in-page links on a wide screen, and the two ways in. Transparent over the hero, and a
    blurred canvas with a hairline once the page has scrolled. A phone keeps just "Sign in" (the hero has both buttons).
--}}
<header
    x-data="{ scrolled: false }"
    x-init="scrolled = window.scrollY > 8"
    @scroll.window.passive="scrolled = window.scrollY > 8"
    class="fixed inset-x-0 top-0 z-40 border-b border-transparent motion-safe:transition-[background-color,border-color] motion-safe:duration-(--motion-base)"
    :class="scrolled && 'border-hairline! bg-canvas/80 backdrop-blur-md'"
>
    <div class="flex min-h-16 items-center justify-between gap-4 px-gutter">
        <x-app-logo href="{{ route('landing') }}" />

        <nav aria-label="{{ __('On this page') }}" class="max-md:hidden">
            <ul class="flex items-center gap-1">
                @foreach ([['#top-10', __('Top 10')], ['#together', __('Why Bookflix')], ['#faq', __('FAQ')]] as [$href, $label])
                    <li><a href="{{ $href }}" class="focus-ring inline-flex min-h-11 items-center rounded-control px-3 text-meta text-ink-subtle transition-colors hover:text-ink">{{ $label }}</a></li>
                @endforeach
            </ul>
        </nav>

        <div class="flex items-center gap-3">
            <x-cta variant="secondary" :href="route('login')">{{ __('Sign in') }}</x-cta>
            <x-cta :href="route('register')" class="max-sm:hidden">{{ __('Get started') }}</x-cta>
        </div>
    </div>
</header>
