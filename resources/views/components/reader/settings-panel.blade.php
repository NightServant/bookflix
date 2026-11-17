@props(['variant' => 'book', 'direction' => 'ltr'])

@php
    // Text sizes the stepper walks through, in percent; the middle one is 100%.
    $sizes = [90, 100, 115, 130, 150];
    $heading = 'mb-2 block text-meta text-ink-muted';
@endphp

{{--
    Reading settings (signed-off frames d02 and m02). `book` is the EPUB panel, `pages` the comic's, `strip` the webtoon's (its width only). On a desktop it is a 384px popover under the Aa button,
    right aligned with an 8px gap; on a phone a bottom sheet with a grabber. The values live in the panel's own Alpine state and go out as one
    `reader:settings` event on the window; where they are kept on the device is the chrome script's (UI-1b). Nothing here is red.
--}}
<div
    id="reader-settings"
    data-reader="settings-panel"
    role="dialog"
    aria-modal="true"
    aria-labelledby="reader-settings-title"
    x-show="panel === 'settings'"
    x-trap="panel === 'settings'"
    x-cloak
    style="display: none"
    x-data="{
        s: @js($variant === 'book'
            ? ['fontSize' => 100, 'lineHeight' => '1.6', 'typeface' => 'book', 'layout' => 'paginated', 'theme' => 'dark']
            : ($variant === 'strip'
                ? ['fit' => 'standard']
                : ['direction' => $direction === 'rtl' ? 'rtl' : 'ltr', 'fit' => 'height', 'spread' => 'one'])),
        sizes: @js($sizes),
        step(n) { const i = Math.min(this.sizes.length - 1, Math.max(0, this.sizes.indexOf(this.s.fontSize) + n)); this.s.fontSize = this.sizes[i]; },
        init() { this.$watch('s', () => window.dispatchEvent(new CustomEvent('reader:settings', { detail: { ...this.s } }))); },
    }"
    {{ $attributes->class('fixed inset-x-0 bottom-0 z-50 max-h-[85dvh] overflow-y-auto rounded-t-card border border-b-0 border-hairline bg-surface-card p-6 shadow-overlay lg:absolute lg:inset-x-auto lg:end-6 lg:top-18 lg:bottom-auto lg:w-96 lg:rounded-card lg:border-b') }}
>
    <div aria-hidden="true" class="mx-auto -mt-3 mb-3 h-1 w-10 rounded-full bg-ink-muted/60 lg:hidden"></div>

    <div class="flex items-center justify-between gap-4">
        <h2 id="reader-settings-title" class="text-lead text-ink">{{ __('Reading settings') }}</h2>
        <button type="button" @click="panel = null" aria-label="{{ __('Close reading settings') }}" class="focus-ring -me-2 inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-ink hover:bg-surface-input">
            <flux:icon name="x-mark" variant="outline" class="size-6" />
        </button>
    </div>

    <div class="mt-4 flex flex-col gap-5">
        @if ($variant === 'book')
            <div role="group" aria-labelledby="reader-text-size">
                <span id="reader-text-size" class="{{ $heading }}">{{ __('Text size') }}</span>
                <div class="flex items-center gap-3">
                    <button type="button" data-reader="text-smaller" @click="step(-1)" :disabled="s.fontSize === sizes[0]" aria-label="{{ __('Smaller text') }}" class="focus-ring inline-flex min-h-11 min-w-11 items-center justify-center rounded-control bg-surface-input text-meta text-ink disabled:opacity-40"><span aria-hidden="true">A</span></button>
                    <div class="flex flex-1 items-center justify-between" aria-hidden="true">
                        @foreach ($sizes as $size)
                            <span :class="s.fontSize === {{ $size }} ? 'size-5 bg-ink' : 'size-2 bg-ink'" class="rounded-full"></span>
                        @endforeach
                    </div>
                    <button type="button" data-reader="text-larger" @click="step(1)" :disabled="s.fontSize === sizes[sizes.length - 1]" aria-label="{{ __('Larger text') }}" class="focus-ring inline-flex min-h-11 min-w-11 items-center justify-center rounded-control bg-surface-input text-lead text-ink disabled:opacity-40"><span aria-hidden="true">A</span></button>
                    <span data-reader="text-size-value" aria-live="polite" class="w-12 text-end text-meta text-ink" x-text="s.fontSize + '%'">100%</span>
                </div>
            </div>

            <div>
                <span id="reader-line-spacing" class="{{ $heading }}">{{ __('Line spacing') }}</span>
                <x-reader.segmented :label="__('Line spacing')" name="lineHeight" model="s.lineHeight" :options="['1.4' => __('Compact'), '1.6' => __('Normal'), '1.8' => __('Relaxed')]" />
            </div>

            <div>
                <span class="{{ $heading }}">{{ __('Typeface') }}</span>
                <x-reader.segmented :label="__('Typeface')" name="typeface" model="s.typeface" :options="['book' => __('Book'), 'serif' => __('Serif'), 'sans' => __('Sans')]" />
            </div>

            <div>
                <span class="{{ $heading }}">{{ __('Layout') }}</span>
                <x-reader.segmented :label="__('Layout')" name="layout" model="s.layout" :options="['paginated' => __('Pages'), 'scrolled' => __('Scroll')]" />
            </div>

            <div>
                <span id="reader-theme" class="{{ $heading }}">{{ __('Theme') }}</span>
                <div role="radiogroup" aria-labelledby="reader-theme" class="flex gap-3"
                    @keydown="if (['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp'].includes($event.key)) { $event.preventDefault(); s.theme = s.theme === 'dark' ? 'sepia' : 'dark'; $nextTick(() => $el.querySelector('[aria-checked=true]').focus()); }">
                    @foreach (['dark' => [__('Dark'), 'bg-reader-dark text-reader-dark-ink'], 'sepia' => [__('Sepia dark'), 'bg-reader-sepia text-reader-sepia-ink']] as $value => [$name, $swatch])
                        <button
                            type="button"
                            role="radio"
                            data-reader-option="theme:{{ $value }}"
                            aria-checked="false"
                            :aria-checked="s.theme === '{{ $value }}'"
                            :tabindex="s.theme === '{{ $value }}' ? 0 : -1"
                            @click="s.theme = '{{ $value }}'"
                            class="focus-ring flex min-h-11 w-36 flex-col gap-1.5 text-start text-meta text-ink"
                        >
                            <span :class="s.theme === '{{ $value }}' ? 'border-2 border-accent-electric' : 'border border-border-input'" class="flex h-16 w-full items-center justify-center rounded-card font-serif text-lead {{ $swatch }}"><span aria-hidden="true">Aa</span></span>
                            <span class="flex items-center gap-1.5">{{ $name }}<flux:icon x-show="s.theme === '{{ $value }}'" style="display: none" name="check" variant="micro" class="size-4" /></span>
                        </button>
                    @endforeach
                </div>
            </div>
        @elseif ($variant === 'strip')
            <div>
                <span class="{{ $heading }}">{{ __('Strip width') }}</span>
                <x-reader.segmented :label="__('Strip width')" name="fit" model="s.fit" :options="['standard' => __('Standard'), 'wide' => __('Full width')]" />
            </div>

            <p class="text-legal text-ink-muted">{{ __('Webtoons scroll as one strip. Your choice is kept for this story on this device.') }}</p>
        @else
            <div>
                <span class="{{ $heading }}">{{ __('Page direction') }}</span>
                <x-reader.segmented :label="__('Page direction')" name="direction" model="s.direction" :options="['rtl' => __('Right to left'), 'ltr' => __('Left to right')]" />
            </div>

            <div>
                <span class="{{ $heading }}">{{ __('Page size') }}</span>
                <x-reader.segmented :label="__('Page size')" name="fit" model="s.fit" :options="['height' => __('Fit height'), 'width' => __('Fit width')]" />
            </div>

            <div class="max-lg:hidden">
                <span class="{{ $heading }}">{{ __('Pages on screen') }}</span>
                <x-reader.segmented :label="__('Pages on screen')" name="spread" model="s.spread" :options="['one' => __('One page'), 'two' => __('Two pages')]" />
            </div>

            <p class="text-legal text-ink-muted">{{ __('Manga from Japan opens right to left. Your choice is kept for this story on this device.') }}</p>
        @endif
    </div>
</div>
