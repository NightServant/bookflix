@props(['model', 'label', 'status'])

{{--
    The filter-the-list field of a big group in <x-filter-panel> (genres, themes, tropes, content warnings): it narrows the rows below as you type and says how many match
    in a polite live region, so a screen reader hears the count. `model` is the key of the panel's `query`, `status` the expression that reads the count message.
--}}
<div class="relative">
    <label class="sr-only" for="fp-search-{{ $model }}">{{ $label }}</label>
    <flux:icon name="magnifying-glass" variant="micro" class="pointer-events-none absolute start-3 top-1/2 size-5 -translate-y-1/2 text-ink-subtle" />
    <input
        id="fp-search-{{ $model }}"
        type="search"
        x-model="query.{{ $model }}"
        autocomplete="off"
        placeholder="{{ $label }}"
        class="focus-ring h-11 w-full rounded-control border border-border-input bg-surface-input ps-10 pe-3 text-body text-ink placeholder:text-ink-subtle"
    >
</div>
<p role="status" class="sr-only" x-text="{{ $status }}"></p>
