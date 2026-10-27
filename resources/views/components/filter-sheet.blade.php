@props([
    'name' => 'filters',
    'title' => null,
])

{{--
    The mobile filters: a "Filters" button (hidden from the lg breakpoint, where the page shows the filters inline) opens a
    right-hand flyout (flux:modal variant="flyout", a native modal <dialog>: focus is trapped, Escape closes, focus goes back to the button).
    The slot holds the filter controls. Pass `name` when a page has more than one sheet.
    Flux does not name its dialog or honour reduced motion; the heading names it below and resources/css/app.css snaps the transition.
--}}
@php
    $title ??= __('Filters');
    $headingId = 'filter-sheet-'.Str::slug($name).'-title';
@endphp

<div
    x-data
    @resize.window.debounce.150ms="innerWidth >= 1024 && $flux.modal(@js($name)).close()"
    {{ $attributes->class('lg:hidden') }}
>
    <flux:modal.trigger :name="$name">
        <x-cta variant="secondary" icon="adjustments-horizontal">{{ $title }}</x-cta>
    </flux:modal.trigger>

    <flux:modal :name="$name" variant="flyout" :closable="false" class="w-full max-w-sm text-ink">
        <div
            x-init="$el.closest('dialog').setAttribute('aria-labelledby', '{{ $headingId }}')"
            @keydown.tab.prevent="$focus.wrap().next()"
            @keydown.shift.tab.prevent="$focus.wrap().previous()"
            class="flex min-h-full flex-col gap-6"
        >
            <div class="flex items-center justify-between gap-4">
                <h2 id="{{ $headingId }}" class="text-lead text-ink">{{ $title }}</h2>

                <flux:modal.close>
                    <button type="button" autofocus aria-label="{{ __('Close filters') }}" class="focus-ring inline-flex size-11 items-center justify-center rounded-control text-ink-subtle hover:bg-surface-input hover:text-ink">
                        <flux:icon name="x-mark" class="size-5" />
                    </button>
                </flux:modal.close>
            </div>

            <div class="flex-1">
                {{ $slot }}
            </div>

            <flux:modal.close>
                <x-cta icon="check" class="w-full">{{ __('Show results') }}</x-cta>
            </flux:modal.close>
        </div>
    </flux:modal>
</div>
