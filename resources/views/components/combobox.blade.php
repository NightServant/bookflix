@props([
    'options' => [],
    'label',
    'multiple' => false,
    'placeholder' => null,
    'hideLabel' => false,
    'labelClass' => null,
    'freeform' => false,
])

{{--
    A combobox after the APG pattern, on @alpinejs/ui: a text field that filters a popup list of options.
    `options` is a value => label map (or a plain list, where each string is its own value). `label` is required and a real <label>
    (`hide-label` keeps it for screen readers only). Bind with `wire:model` or `x-model`: a string, or an array of strings with `multiple`.
    Multiple mode keeps the list open and shows the picks as chips, each with a remove button.
    `freeform` turns it into a search field with suggestions: the typed text is the model, a suggestion fills the field, and Enter
    keeps what was typed unless an arrow key chose a suggestion. `label-class` replaces the label's styling.
    The ui plugin adds the combobox, listbox and option roles, the arrow/Enter/Escape keys and aria-activedescendant at runtime.
    The ui plugin needs an x-data ancestor, so the state lives on the wrapper and the combobox element only takes the model attributes.
--}}
@php
    $items = collect($options)
        ->map(fn ($text, $key) => ['value' => (string) (array_is_list($options) ? $text : $key), 'label' => (string) $text])
        ->values()
        ->all();

    $modelAttributes = $attributes->whereStartsWith(['wire:model', 'x-model', 'name']);
@endphp

<div
    x-data="comboboxField(@js($items), { multiple: {{ $multiple ? 'true' : 'false' }}, freeform: {{ $freeform ? 'true' : 'false' }} })"
    {{ $attributes->whereDoesntStartWith(['wire:model', 'x-model', 'name'])->class('relative') }}
>
    <div x-combobox @if ($multiple) multiple @endif {{ $modelAttributes }} @keydown.escape.capture="$combobox.isOpen || $event.stopPropagation()">
        <label x-combobox:label class="{{ $hideLabel ? 'sr-only' : ($labelClass ?? 'mb-2 block text-meta font-medium text-ink') }}">{{ $label }}</label>

        @if ($multiple)
            <ul aria-label="{{ __('Selected') }}" x-show="$combobox.value.length" style="display: none" class="mb-2 flex flex-wrap gap-2">
                <template x-for="value in $combobox.value" :key="value">
                    <li class="inline-flex h-9 items-center rounded-full bg-surface-input ps-3 text-meta text-ink">
                        <span x-text="labelFor(value)"></span>
                        <button
                            type="button"
                            :aria-label="@js(__('Remove')) + ' ' + labelFor(value)"
                            @click="remove(value)"
                            class="focus-ring relative inline-flex size-9 items-center justify-center rounded-full text-ink-subtle after:absolute after:-inset-y-1 after:-inset-x-0.5 after:content-[''] hover:text-ink"
                        >
                            <flux:icon name="x-mark" class="size-4" />
                        </button>
                    </li>
                </template>
            </ul>
        @endif

        <div class="relative">
            <flux:input
                x-combobox:input
                x-bind:display-value="(value) => labelFor(value)"
                @change="filter($el)"
                @input="typed($el)"
                @keydown.down.capture="beforeArrow($event)"
                @keydown.enter.capture="beforePick($event)"
                @keydown.enter="afterPick($el)"
                :icon="$freeform ? 'magnifying-glass' : null"
                :placeholder="$placeholder"
                autocomplete="off"
                class:input="h-11! rounded-control! pe-12"
            />
            <button
                x-combobox:button
                @if ($freeform) hidden @endif
                type="button"
                aria-label="{{ __('Show options') }}"
                class="focus-ring absolute inset-y-0 end-0 inline-flex size-11 items-center justify-center rounded-control text-ink-subtle hover:text-ink"
            >
                <flux:icon name="chevron-up-down" class="size-5" />
            </button>
        </div>

        <ul
            x-combobox:options
            @if ($multiple) aria-multiselectable="true" @endif
            style="display: none"
            class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-card border border-hairline bg-surface-card p-1 not-has-[li]:hidden"
        >
            <template x-for="option in filtered" :key="option.value">
                <li
                    x-combobox:option
                    :value="option.value"
                    :class="{ 'bg-surface-input': $comboboxOption.isActive }"
                    class="flex min-h-11 cursor-pointer items-center justify-between gap-2 rounded-control px-3 text-body text-ink"
                >
                    <span x-text="option.label"></span>
                    <flux:icon name="check" x-show="$comboboxOption.isSelected" style="display: none" class="size-5 shrink-0" />
                </li>
            </template>
        </ul>

        @unless ($freeform)
            <p
                role="status"
                x-show="$combobox.isOpen && filtered.length === 0"
                style="display: none"
                class="absolute z-20 mt-1 w-full rounded-card border border-hairline bg-surface-card p-3 text-meta text-ink-subtle"
            >{{ __('No matches') }}</p>
        @endunless
    </div>
</div>
