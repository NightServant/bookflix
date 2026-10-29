@props(['options', 'legend', 'hideLegend' => false])

{{--
    A group of chips the reader ticks any number of: a fieldset of native checkboxes, so the keyboard, the screen reader and the form state all
    come for free. Bind it with `wire:model` (or `x-model`) to an array; each chip's value is the key of `options`, its label the value. A ticked chip fills
    with ink and shows a check, and the focus ring is drawn on the chip from the hidden checkbox. 44px tall. The model attributes go to the
    checkboxes, every other attribute to the fieldset. `legend` names the group (kept for screen readers with `hideLegend`).
--}}
<fieldset {{ $attributes->whereDoesntStartWith(['wire:model', 'x-model'])->class('min-w-0') }}>
    <legend @class(['sr-only' => $hideLegend, 'mb-3 text-meta text-ink-subtle' => ! $hideLegend])>{{ $legend }}</legend>

    <div class="flex flex-wrap gap-2">
        @foreach ($options as $value => $label)
            <label wire:key="chip-{{ $value }}" class="group cursor-pointer">
                <input type="checkbox" value="{{ $value }}" class="peer sr-only" {{ $attributes->whereStartsWith(['wire:model', 'x-model']) }}>
                <span class="inline-flex min-h-11 items-center gap-2 rounded-full bg-surface-input px-4 text-meta text-ink hover:bg-hairline peer-checked:bg-ink peer-checked:text-canvas peer-checked:hover:bg-ink peer-focus-visible:ring-2 peer-focus-visible:ring-accent-electric peer-focus-visible:ring-offset-2 peer-focus-visible:ring-offset-canvas motion-safe:transition-colors motion-safe:duration-(--motion-fast)">
                    <flux:icon name="check" variant="micro" class="hidden size-4 group-has-[:checked]:block" />
                    {{ $label }}
                </span>
            </label>
        @endforeach
    </div>
</fieldset>
