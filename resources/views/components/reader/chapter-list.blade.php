@props(['percent' => 0])

{{--
    Contents (signed-off frames d03 and m03): a side sheet 400px wide from the left under the top bar with a scrim over the rest on a desktop, the whole
    screen under the top bar on a phone. The chapters (`toc`: label, href, level) come from the engine's `reader:ready` event into the page's Alpine
    state; a row is a button that goes to its `href`. The current chapter has `bg-surface-input`, a check and medium weight. Focus is trapped while it is
    open and returns to the Contents button; Esc closes it.
--}}
<div
    id="reader-contents"
    data-reader="contents"
    role="dialog"
    aria-modal="true"
    aria-labelledby="reader-contents-title"
    x-show="panel === 'contents'"
    x-trap="panel === 'contents'"
    x-cloak
    style="display: none"
    {{ $attributes->class('fixed inset-x-0 top-16 bottom-0 z-30 flex') }}
>
    <div class="flex w-full flex-col overflow-y-auto bg-surface-card lg:w-100 lg:border-e lg:border-hairline">
        <div class="flex items-center justify-between gap-4 px-6 pt-6 pb-3">
            <h2 id="reader-contents-title" class="text-section text-ink">{{ __('Contents') }}</h2>
            <button type="button" @click="panel = null" aria-label="{{ __('Close contents') }}" class="focus-ring -me-2 inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-ink hover:bg-surface-input">
                <flux:icon name="x-mark" variant="outline" class="size-6" />
            </button>
        </div>

        <nav aria-label="{{ __('Chapters') }}" class="px-4">
            <ol class="flex flex-col">
                <template x-for="(item, i) in toc" :key="i">
                    <li>
                        <button
                            type="button"
                            data-reader="chapter"
                            @click="$dispatch('reader:goto', { href: item.href }); panel = null"
                            :aria-current="current === item.href ? 'true' : null"
                            :class="current === item.href ? 'bg-surface-input font-medium text-ink' : 'text-ink-subtle hover:bg-surface-input'"
                            :style="'padding-inline-start: ' + (2.5 + (item.level || 0) * 1) + 'rem'"
                            class="focus-ring relative flex min-h-12 w-full items-center rounded-card pe-3 text-start text-body"
                        >
                            <flux:icon x-show="current === item.href" style="display: none" name="check" variant="outline" class="absolute start-2.5 size-5" />
                            <span x-text="item.label"></span>
                        </button>
                    </li>
                </template>
            </ol>
            <p x-show="toc.length === 0" style="display: none" class="px-2 py-3 text-meta text-ink-muted">{{ __('This book has no list of chapters.') }}</p>
        </nav>

        <p data-reader="contents-footer" class="px-6 py-4 text-legal text-ink-muted" x-text="contentsNote">{{ __(':percent% of the book read', ['percent' => rtrim(rtrim(number_format((float) $percent, 1, '.', ''), '0'), '.')]) }}</p>
    </div>

    {{-- The rest of the page, dimmed; a click on it closes the sheet (a phone's sheet covers all of it). --}}
    <button type="button" tabindex="-1" aria-hidden="true" @click="panel = null" class="hidden flex-1 cursor-default bg-canvas/60 lg:block"></button>
</div>
