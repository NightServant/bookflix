@props(['title', 'description' => null, 'danger' => false])

{{--
    One group of related settings: a section heading, a plain line of help, then the controls. `danger` marks the destructive group,
    set apart by a dashed hairline instead of the filled card. Headings are h2s under the page's h1.
--}}
<section {{ $attributes->class(['rounded-card p-5 sm:p-6', $danger ? 'border border-dashed border-hairline' : 'bg-surface-card']) }}>
    <header>
        <h2 class="text-lead text-ink">{{ $title }}</h2>
        @if ($description)
            <p class="mt-1 max-w-prose text-meta text-ink-subtle text-pretty">{{ $description }}</p>
        @endif
    </header>

    <div class="mt-6">{{ $slot }}</div>
</section>
