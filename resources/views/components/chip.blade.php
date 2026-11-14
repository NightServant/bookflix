@props([
    'active' => false,
    'intent' => null,
])

{{--
    A link chip: a 36px pill with `text-meta`, whose touch target is still 44px tall (the `after` pseudo-element reaches 4px past the
    pill above and below). `active` fills it with ink and sets `aria-current`. Used for genres, themes, tags and the format and family
    switches; a chip with a remove button (a picked filter) is built where it is used, from the same sizes.

    `intent` makes it a button for what a search understood (the Search page): 'applied' is an outlined pill with an × (tap to remove it from the
    search), 'suggested' a dashed one with a + (tap to add it). The icon is decoration; name the button with `aria-label`.
--}}
@if ($intent)
    <button
        type="button"
        {{ $attributes->class([
            'focus-ring relative inline-flex h-9 items-center gap-2 rounded-full text-meta text-ink after:absolute after:inset-x-0 after:-inset-y-1 after:content-[\'\'] hover:bg-surface-input',
            'ps-3 pe-2.5 ring-1 ring-inset ring-border-input' => $intent === 'applied',
            'border border-dashed border-border-input ps-2.5 pe-3' => $intent === 'suggested',
        ]) }}
    >
        @if ($intent === 'suggested')
            <flux:icon name="plus" variant="micro" class="size-4 text-ink-subtle" />
        @endif
        <span>{{ $slot }}</span>
        @if ($intent === 'applied')
            <flux:icon name="x-mark" variant="micro" class="size-4 text-ink-subtle" />
        @endif
    </button>
@else
    <a
        @if ($active) aria-current="true" @endif
        {{ $attributes->class([
            'focus-ring relative inline-flex h-9 items-center gap-2 rounded-full px-3 text-meta after:absolute after:inset-x-0 after:-inset-y-1 after:content-[\'\']',
            'bg-ink text-canvas' => $active,
            'bg-surface-input text-ink hover:bg-hairline' => ! $active,
        ]) }}
    >{{ $slot }}</a>
@endif
