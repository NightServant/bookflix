@props([
    'name' => 'filters',
    'title' => null,
])

{{--
    The mobile filters: a "Filters" button (hidden from the lg breakpoint, where the page shows the filters inline) opens a
    right-hand flyout (flux:modal variant="flyout", a native modal <dialog>: focus is trapped, Escape closes, focus goes back to the button).
    The slot holds the filter controls. Pass `name` when a page has more than one sheet.
    Two optional slots: `footer` replaces the "Show results" button (a page that applies filters on its own button puts its Clear all and Apply
    there; they sit at the foot of the sheet however far the filters scroll), and `badge` goes inside the trigger, after its label (the count of
    applied filters). Closing the sheet by any means fires `filter-sheet-close` on the sheet's body, which bubbles, so a page can drop a draft.
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
        <x-cta variant="secondary" icon="adjustments-horizontal">{{ $title }}{{ $badge ?? '' }}</x-cta>
    </flux:modal.trigger>

    <flux:modal :name="$name" variant="flyout" :closable="false" class="w-full max-w-sm text-ink shadow-overlay!">
        <div
            x-init="const dialog = $el.closest('dialog'); dialog.setAttribute('aria-labelledby', '{{ $headingId }}'); dialog.addEventListener('close', () => $el.dispatchEvent(new CustomEvent('filter-sheet-close', { bubbles: true })))"
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

            {{-- Stays at the foot of the sheet however far the filters scroll. --}}
            <div class="sticky -bottom-8 -mx-8 -mb-8 border-t border-hairline bg-surface-card px-8 pt-4 pb-8">
                @isset($footer)
                    {{ $footer }}
                @else
                    <flux:modal.close>
                        <x-cta icon="check" class="w-full">{{ __('Show results') }}</x-cta>
                    </flux:modal.close>
                @endisset
            </div>
        </div>
    </flux:modal>
</div>
