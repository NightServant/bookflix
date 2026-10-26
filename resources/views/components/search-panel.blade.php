@props([
    'placeholder' => null,
    'suggestions' => [],
])

{{--
    The "What would you like to find?" panel that opens the Search and Discover screens: one search field with optional typeahead
    `suggestions` (a list of strings) and a secondary way into Discover. The field is a freeform <x-combobox>: bind the text with
    `wire:model` (or `.live.debounce` for results as you type); the label is a real <label>.
    Discover's route comes from the `Discover` item of config('bookflix.navigation').
--}}
@php
    $discover = collect(config('bookflix.navigation'))->firstWhere('label', 'Discover');
@endphp

<search {{ $attributes->only('class')->class('flex flex-col gap-4 sm:flex-row sm:items-end') }}>
    <x-combobox
        freeform
        :label="__('What would you like to find?')"
        label-class="mb-2 block text-lead text-ink"
        :options="$suggestions"
        :placeholder="$placeholder"
        class="min-w-0 flex-1"
        {{ $attributes->except('class') }}
    />

    @if ($discover)
        <x-cta variant="secondary" :href="route($discover['route'])" wire:navigate>{{ __('Browse Discover') }}</x-cta>
    @endif
</search>
