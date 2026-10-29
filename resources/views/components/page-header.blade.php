@props(['title', 'description', 'eyebrow' => null])

{{--
    The heading every routed page opens with: the one page-title scale, then a plain line (two sentences at most) saying what the
    page is for and what to do next. Sits on the header's gutter when the page is not `flush`; a page that builds its own bands
    puts this inside its `px-gutter` band. `actions` is an optional slot for the page's buttons, right-aligned beside the title.
    Home's hero keeps `text-hero`, so it does not use this.
--}}
<header {{ $attributes->class('flex flex-wrap items-end justify-between gap-x-6 gap-y-4') }}>
    <div class="min-w-0">
        @if ($eyebrow)
            <p class="mb-2 text-meta text-ink-subtle">{{ $eyebrow }}</p>
        @endif
        <h1 class="line-clamp-2 break-words text-section tracking-tight text-balance text-ink md:text-title">{{ $title }}</h1>
        <p class="mt-2 max-w-prose text-body text-ink-subtle text-pretty">{{ $description }}</p>
    </div>

    @if (isset($actions) && $actions->isNotEmpty())
        <div class="flex shrink-0 flex-wrap items-center gap-3">{{ $actions }}</div>
    @endif
</header>
