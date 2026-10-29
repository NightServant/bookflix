@props([
    'title',
    'description',
])

{{-- The card's heading: the page-header pattern (h1, then a plain guide line) scaled for a form card. --}}
<header class="flex w-full flex-col">
    <h1 class="text-section tracking-tight text-balance text-ink">{{ $title }}</h1>
    <p class="mt-2 text-body text-ink-subtle text-pretty">{{ $description }}</p>
</header>
