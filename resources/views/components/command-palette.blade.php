@props(['label' => null])

{{--
    The search palette: a trigger button, plus ⌘K / Ctrl-K from anywhere on the page, opens a modal dialog (@alpinejs/ui dialog,
    focus trapped, Escape closes) holding a combobox over the Livewire search results (`command-palette-results`).
    The card is one piece: a header row (search icon, borderless field, the 44px close button), a hairline, then the results
    (see `command-palette-results`, which owns the row so the field and its close button share it).
    Escape closes the result list first, then the dialog. Closing returns focus to whatever opened it.
    The app header renders it for every signed-in page.
--}}
<div
    x-data="{ open: false }"
    @keydown.window.prevent.meta.k="open = true"
    @keydown.window.prevent.ctrl.k="open = true"
    @palette-close="open = false"
    {{ $attributes }}
>
    <button
        type="button"
        @click="open = true"
        aria-haspopup="dialog"
        aria-keyshortcuts="Control+K Meta+K"
        class="focus-ring inline-flex min-h-11 items-center gap-2 rounded-control bg-surface-input px-3 text-meta text-ink-subtle hover:bg-hairline hover:text-ink"
    >
        <flux:icon name="magnifying-glass" class="size-5" />
        <span>{{ $label ?? __('Search') }}</span>
        <kbd x-text="/Mac|iPhone|iPad/.test(navigator.userAgent) ? '⌘K' : 'Ctrl K'" class="hidden text-legal text-ink-subtle sm:inline" aria-hidden="true">⌘K</kbd>
    </button>

    <div
        x-dialog
        x-model="open"
        style="display: none"
        class="fixed inset-0 z-50 flex items-start justify-center p-4 sm:pt-24"
    >
        <div x-dialog:overlay class="fixed inset-0 bg-canvas/80"></div>

        <div
            x-dialog:panel
            x-motion-expand="open"
            data-palette-panel
            class="relative w-full max-w-2xl overflow-hidden rounded-card border border-hairline bg-surface-card"
        >
            <h2 x-dialog:title class="sr-only">{{ __('Search the Library') }}</h2>
            <p x-dialog:description class="sr-only">{{ __('Type to search. Use the arrow keys to choose a result and Enter to open it.') }}</p>

            <livewire:command-palette-results />
        </div>
    </div>
</div>
