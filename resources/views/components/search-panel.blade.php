@props([
    'placeholder' => null,
    'suggestions' => [],
    'action' => null,
    'browse' => true,
])

{{--
    The "What would you like to find?" panel that opens the Search, Discover and Home screens: one search field with optional typeahead
    `suggestions` (a list of strings) and, unless `browse` is false, a secondary way into Discover. The field is a freeform <x-combobox>:
    bind the text with `wire:model` (or `.live.debounce` for results as you type); the label is a real <label>.
    Pass `action` (a URL, usually route('search')) instead of a binding on a screen that is not the results: Enter then opens that URL
    with the text as `?q=` (two letters at least, the search's own minimum), over wire:navigate.
    The field is a standard 44px high and at most 672px wide (it never stretches across a wide page); the secondary cta sits right
    next to it, wrapping beneath it on a phone.
    Discover's route comes from the `Discover` item of config('bookflix.navigation').
--}}
@php
    $discover = $browse ? collect(config('bookflix.navigation'))->firstWhere('label', 'Discover') : null;
@endphp

<search
    {{ $attributes->only('class')->class('flex flex-col gap-4 sm:flex-row sm:items-end') }}
    @if ($action)
        x-data="{ q: '' }"
        @keydown.enter.capture="if ($event.target.matches('[role=combobox]') && !$event.isComposing && q.trim().length >= 2) { $event.preventDefault(); Livewire.navigate(@js($action).concat('?q=', encodeURIComponent(q.trim()))) }"
    @endif
>
    <x-combobox
        freeform
        :label="__('What would you like to find?')"
        label-class="mb-2 block text-lead text-ink"
        :options="$suggestions"
        :placeholder="$placeholder"
        class="w-full min-w-0 sm:max-w-2xl sm:flex-1"
        {{ $attributes->except('class')->merge($action ? ['x-model' => 'q'] : []) }}
    />

    @if ($discover)
        <x-cta variant="secondary" icon="compass" :href="route($discover['route'])" wire:navigate>{{ __('Browse Discover') }}</x-cta>
    @endif
</search>
