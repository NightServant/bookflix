@props(['variant' => 'primary'])

{{--
    The call to action. `primary` is the red button (the only red control; one per view); `secondary` is the quiet one.
    Pass `href` for a link or `wire:click`/`@click` for a button; sentence-case label in the slot.
    Flux's own 40px height, small text and inset highlight are overridden to the kit's 24px label, 22px x 8px padding and 44px floor.
--}}
<flux:button
    :variant="$variant === 'secondary' ? 'filled' : 'primary'"
    {{ $attributes->class('focus-ring h-auto! min-h-11 px-[22px]! py-2! text-cta! shadow-none!') }}
>{{ $slot }}</flux:button>
