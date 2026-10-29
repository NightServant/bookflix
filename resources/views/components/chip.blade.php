@props([
    'active' => false,
])

{{--
    A link chip: a 36px pill with `text-meta`, whose touch target is still 44px tall (the `after` pseudo-element reaches 4px past the
    pill above and below). `active` fills it with ink and sets `aria-current`. Used for genres, themes, tags and the format and family
    switches; a chip with a remove button (a picked filter) is built where it is used, from the same sizes.
--}}
<a
    @if ($active) aria-current="true" @endif
    {{ $attributes->class([
        'focus-ring relative inline-flex h-9 items-center gap-2 rounded-full px-3 text-meta after:absolute after:inset-x-0 after:-inset-y-1 after:content-[\'\']',
        'bg-ink text-canvas' => $active,
        'bg-surface-input text-ink hover:bg-hairline' => ! $active,
    ]) }}
>{{ $slot }}</a>
