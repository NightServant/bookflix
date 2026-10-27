@props([
    'label',
    'min',
    'max',
    'step' => 1,
])

{{--
    A two-handle range slider (nouislider) for a numeric filter such as publication year. Bind both ends with `wire:model.live`
    (or `x-model`) to a two-item array, `[from, to]`; the value is written when a drag or key press ends, not on every pixel.
    Handles are role="slider" with aria-valuemin/max/now and a name; arrows, Home/End and Page keys come from nouislider.
    The slider lives inside `wire:ignore` and is built in Alpine's init and destroyed on teardown, so it survives a Livewire
    morph and a wire:navigate round trip. Its CSS is imported in resources/css/app.css and restyled there with tokens.
--}}
@php
    $names = [__(':label, minimum', ['label' => $label]), __(':label, maximum', ['label' => $label])];
@endphp

<div
    x-data="rangeFilter({ min: {{ (int) $min }}, max: {{ (int) $max }}, step: {{ (int) $step }}, names: @js($names) })"
    x-modelable="values"
    {{ $attributes }}
>
    <div class="flex items-baseline justify-between gap-4">
        <span class="text-meta font-medium text-ink">{{ $label }}</span>
        <output class="text-meta tabular-nums text-ink" x-text="display.join(' – ')">{{ $min }} – {{ $max }}</output>
    </div>

    <div wire:ignore class="px-[1.375rem] py-5">
        <div x-ref="mount"></div>
    </div>
</div>
