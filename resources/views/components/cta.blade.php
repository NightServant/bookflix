@props(['variant' => 'primary', 'icon' => null])

@php
    // `icon:trailing` is not a valid prop name, so it is read off the bag and handed to Flux through the merge below.
    $trailing = $attributes->get('icon:trailing');
    $iconOnly = $slot->isEmpty() && ($icon || $trailing);

    if ($iconOnly && blank($attributes->get('aria-label'))) {
        throw new InvalidArgumentException('An icon-only <x-cta> needs an aria-label.');
    }
@endphp

{{--
    The call to action. `primary` is the red button (the only red control; one per view); `secondary` is the quiet one.
    Pass `href` for a link or `wire:click`/`@click` for a button; sentence-case label in the slot.
    Pass `icon="book-open"` for a leading Heroicon, or `icon:trailing="arrow-right"` for a directional action (a Lucide
    icon from resources/views/flux/icon works too). Icons are the outline set at size-5 beside the 16px label and sit one
    gap-2.5 away; the padding on the icon's side shrinks so label plus icon read as centred. With no label the button is a
    44px square and must carry an aria-label.
    One size everywhere: a 16px label and a 44px floor reached through padding, not type. Flux's own 40px height, small text
    and inset highlight are overridden. The primary button glows red (`--shadow-glow-brand`) on hover only.
--}}
<flux:button
    :variant="$variant === 'secondary' ? 'filled' : 'primary'"
    :icon="$icon"
    :square="$iconOnly ? true : null"
    {{ $attributes->except('icon:trailing')->merge(array_filter([
        'icon:trailing' => $trailing,
        'icon:variant' => ($icon || $trailing) ? 'outline' : null,
        'icon:class' => ($icon || $trailing) ? 'size-5' : null,
    ]))->class([
        'hover:shadow-glow-brand!' => $variant !== 'secondary',
        'focus-ring h-auto! min-h-11 py-2! text-body! font-medium! shadow-none! gap-2.5!',
        'px-5!' => ! $icon && ! $trailing,
        'ps-4! pe-5!' => $icon && ! $trailing && ! $iconOnly,
        'ps-5! pe-4!' => ! $icon && $trailing,
        'px-4!' => $icon && $trailing && ! $iconOnly,
        'w-11 px-0!' => $iconOnly,
    ]) }}
>{{ $slot }}</flux:button>
