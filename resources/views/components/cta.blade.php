@props(['variant' => 'primary'])

{{--
    The call to action. `primary` is the red button (the only red control; one per view); `secondary` is the quiet one.
    Pass `href` for a link or `wire:click`/`@click` for a button; sentence-case label in the slot.
    One size everywhere: a 16px label and a 44px floor reached through padding, not type. Flux's own 40px height, small text
    and inset highlight are overridden.
--}}
<flux:button
    :variant="$variant === 'secondary' ? 'filled' : 'primary'"
    {{ $attributes->class('focus-ring h-auto! min-h-11 px-5! py-2! text-body! font-medium! shadow-none!') }}
>{{ $slot }}</flux:button>
