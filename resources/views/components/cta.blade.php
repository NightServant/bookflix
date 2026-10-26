@props(['variant' => 'primary', 'size' => 'base'])

{{--
    The call to action. `primary` is the red button (the only red control; one per view); `secondary` is the quiet one.
    Pass `href` for a link or `wire:click`/`@click` for a button; sentence-case label in the slot.
    `base` is the everyday size: a 16px label and a 44px floor reached through padding, not type. `lg` is the 24px
    label of the Home hero and nowhere else. Flux's own 40px height, small text and inset highlight are overridden.
--}}
@php
    $sizing = $size === 'lg' ? 'min-h-14 px-6! text-cta!' : 'min-h-11 px-5! text-body! font-medium!';
@endphp

<flux:button
    :variant="$variant === 'secondary' ? 'filled' : 'primary'"
    {{ $attributes->class('focus-ring h-auto! py-2! shadow-none! '.$sizing) }}
>{{ $slot }}</flux:button>
