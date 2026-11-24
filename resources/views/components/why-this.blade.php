@props(['mediaId', 'type', 'reasonMediaId' => null, 'reason'])

{{--
    "Why this?" under a recommended tile, only for a reader who turned AI explanations on (P11-R15, P11-R18; the page decides). A 44px subdued
    button opens a small popover: the template reason first, then one AI sentence loaded on demand (`explain` on the page's Livewire component),
    then the "AI-generated" pill. If the model has nothing to say, only the template reason shows. Escape and a click outside close it and focus
    goes back to the button. Escape is heard on the window in the capture phase because the shelf's story-previews wrapper stops an Escape that reaches it while no
    preview card is open; an Escape that closes this popover goes no further. The panel is teleported to <body> so the shelf's scroll box cannot clip it.
--}}
@php($panel = 'why-this-'.$mediaId)

<div x-data="whyThis(@js(['mediaId' => $mediaId, 'type' => $type, 'reasonMediaId' => $reasonMediaId]))" @keydown.escape.window.capture="close($event)" @click.window="outside($event)" class="-mt-1">
    <button
        type="button"
        x-ref="button"
        @click="toggle()"
        aria-expanded="false"
        :aria-expanded="open ? 'true' : 'false'"
        aria-controls="{{ $panel }}"
        class="focus-ring inline-flex min-h-11 items-center gap-1 rounded-control pe-2 text-legal text-ink-subtle hover:text-ink"
    >
        <flux:icon name="sparkles" variant="micro" class="size-3.5" />
        <span>{{ __('Why this?') }}</span>
    </button>

    <template x-teleport="body">
        <div
            id="{{ $panel }}"
            x-ref="panel"
            x-show="open"
            style="display: none"
            :style="position"
            x-transition:enter="motion-safe:transition motion-safe:duration-(--motion-fast) motion-safe:ease-out-bookflix"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            role="group"
            tabindex="-1"
            aria-label="{{ __('Why this was suggested') }}"
            class="fixed z-50 w-72 max-w-[calc(100vw-2rem)] rounded-card border border-hairline bg-surface-card p-4 text-start shadow-overlay"
        >
            <div aria-live="polite">
                <p class="text-meta text-ink">{{ $reason }}</p>

                <div class="mt-3 border-t border-hairline pt-3">
                <p x-show="state === 'loading'" class="text-meta text-ink-subtle">{{ __('Thinking…') }}</p>
                <div x-show="state === 'done'" style="display: none" class="space-y-2">
                    <p class="text-meta text-ink text-pretty" x-text="sentence"></p>
                    <x-ai-label />
                </div>
                </div>
            </div>
        </div>
    </template>
</div>
