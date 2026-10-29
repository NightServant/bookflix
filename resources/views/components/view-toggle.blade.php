@props(['label' => null])

{{--
    The grid / list switch above results: a radio group after the APG pattern, on @alpinejs/ui (arrows move focus and
    select, only the checked option is in the tab order). Bind it with `wire:model.live` (or `x-model`) to a `grid`|`list` string;
    on a Livewire page keep that property in the URL with `#[Url]`, so the choice survives a reload and can be shared.
    The ui plugin adds the radiogroup and radio roles and `aria-checked` at runtime. `label` names the group.
--}}
@php
    $option = 'focus-ring inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-control px-4 text-meta text-ink-subtle hover:text-ink aria-checked:bg-hairline aria-checked:text-ink';
@endphp

<div x-radio aria-label="{{ $label ?? __('Layout') }}" {{ $attributes->class('inline-flex gap-1 rounded-card bg-surface-input p-1') }}>
    <button x-radio:option type="button" value="grid" class="{{ $option }}">
        <flux:icon name="squares-2x2" class="size-5" />
        <span>{{ __('Grid') }}</span>
    </button>
    <button x-radio:option type="button" value="list" class="{{ $option }}">
        <flux:icon name="list-bullet" class="size-5" />
        <span>{{ __('List') }}</span>
    </button>
</div>
