@props([
    'media',
    'name' => null,
])

{{--
    The touch-device alternative to <x-story-preview>: a visible "Quick view" button (it also names the story for screen readers)
    opens a centered modal with the same <x-story-summary>. It is a flux:modal, a native modal <dialog>: focus is trapped,
    Escape closes, and focus returns to the button. Pass `name` only to change the default `quick-view-{id}`; names must be unique per page.
    Pages that want it for touch only add `class="hidden pointer-coarse:block"`. Flux does not name its dialog, so the title does below.
    Eager-load what <x-story-summary> reads.
--}}
@php
    $name ??= 'quick-view-'.$media->id;
    $titleId = $name.'-title';
@endphp

<div x-data {{ $attributes }}>
    <flux:modal.trigger :name="$name">
        <button type="button" class="focus-ring inline-flex min-h-11 items-center gap-2 rounded-control bg-surface-input px-3 text-meta text-ink hover:bg-hairline">
            <flux:icon name="eye" class="size-5" />
            <span>{{ __('Quick view') }}<span class="sr-only"> {{ __('of :title', ['title' => $media->title]) }}</span></span>
        </button>
    </flux:modal.trigger>

    <flux:modal :name="$name" :closable="false" class="w-full max-w-lg rounded-card! p-4 text-ink sm:p-6">
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

            <x-story-summary :media="$media" :title-id="$titleId" />
        </div>
    </flux:modal>
</div>
