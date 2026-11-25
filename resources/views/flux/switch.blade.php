@blaze(fold: true, unsafe: [
    // flux:with-inline-field props
    'name', 'label', 'description',
])

{{--
    Published from Flux and resized: a 24 x 40 pill (WCAG 2.2's 24px target minimum; Flux's is 20 x 32) with an 18px knob inset 3px on every side, so it
    sits level with a 14px label. The 1px border is there in both states, so nothing shifts when it flips. On is white with a black knob, like the other
    selected controls; red stays for calls to action. Off is the input surface with the input border (3:1 against the canvas).
--}}

@props([
    'name' => null,
    'align' => 'right',
    'checked' => null
])

@php
// We only want to show the name attribute it has been set manually
// but not if it has been set from the `wire:model` attribute...
$showName = isset($name);
if (! isset($name)) {
    $name = $attributes->whereStartsWith('wire:model')->first();
}

$classes = Flux::classes()
    ->add('group focus-ring relative inline-flex h-6 w-10 min-w-10 shrink-0 cursor-pointer items-center rounded-full border')
    ->add('border-border-input bg-surface-input hover:border-ink-subtle')
    ->add('data-checked:border-ink data-checked:bg-ink')
    ->add('motion-safe:transition-colors motion-safe:duration-150')
    ->add('[&[disabled]]:cursor-default [&[disabled]]:opacity-50 data-loading:pointer-events-none data-loading:opacity-50')
    ->add('[print-color-adjust:exact]')
    ;

$indicatorClasses = Flux::classes()
    ->add('size-4.5 rounded-full bg-ink-subtle')
    ->add('translate-x-0.5 rtl:-translate-x-0.5')
    ->add('group-data-checked:translate-x-4.5 rtl:group-data-checked:-translate-x-4.5 group-data-checked:bg-canvas')
    ->add('motion-safe:transition-[translate,background-color] motion-safe:duration-150 motion-safe:ease-out');
@endphp

<?php if ($align === 'left' || $align === 'start'): ?>
    <flux:with-inline-field :$attributes>
        <ui-switch {{ $attributes->class($classes) }} @if($showName) name="{{ $name }}" @endif @if($checked) checked data-checked @endif data-flux-control data-flux-switch>
            <span class="{{ \Illuminate\Support\Arr::toCssClasses($indicatorClasses) }}"></span>
        </ui-switch>
    </flux:with-inline-field>
<?php else: ?>
    <flux:with-reversed-inline-field :$attributes>
        <ui-switch {{ $attributes->class($classes) }} @if($showName) name="{{ $name }}" @endif @if($checked) checked data-checked @endif data-flux-control data-flux-switch>
            <span class="{{ \Illuminate\Support\Arr::toCssClasses($indicatorClasses) }}"></span>
        </ui-switch>
    </flux:with-reversed-inline-field>
<?php endif; ?>
