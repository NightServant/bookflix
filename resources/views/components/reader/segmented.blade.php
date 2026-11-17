@props(['label', 'model', 'options', 'name'])

{{--
    A segmented control as a radio group (settings panel): white fill on the selected option, one tab stop, arrow keys move and select.
    `model` is the Alpine property it sets (on the panel's own state); `options` maps a value to its label. An option given as
    `['label' => ..., 'class' => ...]` may carry extra classes (a choice that is only offered on a wide screen).
--}}
<div
    role="radiogroup"
    aria-label="{{ $label }}"
    @keydown="if (['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp'].includes($event.key)) { $event.preventDefault(); const radios = [...$el.querySelectorAll('[role=radio]')].filter((r) => r.offsetParent !== null); const next = radios[(radios.indexOf(document.activeElement) + (['ArrowRight', 'ArrowDown'].includes($event.key) ? 1 : radios.length - 1)) % radios.length]; next.focus(); next.click(); }"
    {{ $attributes->class('flex rounded-card bg-surface-input p-1') }}
>
    @foreach ($options as $value => $option)
        @php($text = is_array($option) ? $option['label'] : $option)
        <button
            type="button"
            role="radio"
            data-reader-option="{{ $name }}:{{ $value }}"
            aria-checked="false"
            :aria-checked="{{ $model }} === @js($value)"
            :tabindex="{{ $model }} === @js($value) ? 0 : -1"
            @click="{{ $model }} = @js($value)"
            @class([
                'focus-ring min-h-11 flex-1 rounded-control px-3 text-meta font-medium',
                is_array($option) ? ($option['class'] ?? '') : '',
            ])
            :class="{{ $model }} === @js($value) ? 'bg-ink text-canvas' : 'text-ink hover:bg-canvas/30'"
        >{{ $text }}</button>
    @endforeach
</div>
