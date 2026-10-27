@props(['name'])

{{--
    The touch-device dialog <x-story-previews> shares between its tiles: a centered modal that <x-story-preview>'s "Quick view" button
    fills with the story's <x-story-summary>. It is a flux:modal, a native modal <dialog>: focus is trapped, Escape closes, and focus
    returns to the button. `name` is unique per page. Flux does not name its dialog, so the title does below.
--}}
@php
    $titleId = $name.'-title';
@endphp

<flux:modal :name="$name" :closable="false" class="w-full max-w-lg rounded-card! p-4 text-ink shadow-overlay! sm:p-6">
    <div
        x-init="$el.closest('dialog').setAttribute('aria-labelledby', '{{ $titleId }}')"
        @keydown.tab.prevent="$focus.wrap().next()"
        @keydown.shift.tab.prevent="$focus.wrap().previous()"
        class="flex flex-col gap-2"
    >
        <div class="-mt-2 -me-2 flex justify-end">
            <flux:modal.close>
                <button type="button" autofocus aria-label="{{ __('Close quick view') }}" class="focus-ring inline-flex size-11 items-center justify-center rounded-control text-ink-subtle hover:bg-surface-input hover:text-ink">
                    <flux:icon name="x-mark" class="size-5" />
                </button>
            </flux:modal.close>
        </div>

        <div wire:ignore x-effect="fill($el, quick, '{{ $titleId }}')"></div>
    </div>
</flux:modal>
