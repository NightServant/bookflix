@props(['format', 'title', 'subtitle' => null, 'backUrl', 'direction' => 'ltr', 'pageCount' => null, 'page' => 1])

@php
    // `format` is the engine: epub, pdf, pages (a comic paged) or webtoon (one vertical strip).
    $chipLabel = match ($direction) {
        'rtl' => __('Right to left'),
        'vertical' => __('Vertical scroll'),
        default => __('Left to right'),
    };
    $iconButton = 'focus-ring inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-ink hover:bg-surface-input';
    $textButton = 'focus-ring inline-flex min-h-11 min-w-11 items-center justify-center rounded-control px-2 text-body font-medium text-ink hover:bg-surface-input';
@endphp

{{--
    The top bar (64px, signed-off frames d01, d05, d06, d07). Left: Back to the story, the title and a one-line subtitle. Right: the controls of the format.
    The panels it opens (reading settings, contents) are the page's: `panel` is the Alpine state of the page root (resources/js/reader/chrome.js).
    A hook for the scripts is a `data-reader` attribute; nothing here depends on a class.
--}}
<header data-reader="toolbar" {{ $attributes->class('reader-chrome fixed inset-x-0 top-0 z-40 flex h-16 items-center gap-3 border-b border-hairline bg-surface-deep ps-2 pe-2 lg:ps-6 lg:pe-6') }} :class="chrome || panel ? 'opacity-100' : 'pointer-events-none opacity-0'">
    <a href="{{ $backUrl }}" aria-label="{{ __('Back to the story') }}" class="{{ $iconButton }} shrink-0">
        <flux:icon name="arrow-left" variant="outline" class="size-6" />
    </a>

    <div class="min-w-0 flex-1">
        <h1 class="truncate text-body font-medium text-ink">{{ $title }}</h1>
        <p class="truncate text-legal text-ink-subtle first-letter:uppercase"><span data-reader="subtitle" x-text="subtitle">{{ $subtitle }}</span>@if ($format === 'pages')<span x-show="rtl" @if ($direction !== 'rtl') style="display: none" @endif class="sm:hidden"><span x-show="subtitle" @if (! filled($subtitle)) style="display: none" @endif> · </span>{{ __('right to left') }}</span>@endif</p>
    </div>

    <div class="flex shrink-0 items-center gap-1">
        @if ($format === 'pdf')
            <div class="flex items-center gap-2 max-lg:hidden">
                <label for="reader-page-input" class="text-meta text-ink-subtle">{{ __('Page') }}</label>
                <input
                    id="reader-page-input"
                    data-reader="page-input"
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    autocomplete="off"
                    value="{{ $page }}"
                    aria-label="{{ __('Page number') }}"
                    class="focus-ring h-10 w-16 rounded-control border border-border-input bg-surface-input text-center text-body text-ink"
                >
                <span class="text-meta text-ink-subtle">{{ __('of') }} <span data-reader="page-total">{{ $pageCount }}</span></span>
            </div>

            <div class="ms-3 flex items-center gap-1 max-lg:hidden">
                <button type="button" data-reader="zoom-out" aria-label="{{ __('Zoom out') }}" class="{{ $iconButton }}"><flux:icon name="magnifying-glass-minus" variant="outline" class="size-6" /></button>
                <span data-reader="zoom-value" aria-live="polite" class="min-w-12 text-center text-meta font-medium text-ink">100%</span>
                <button type="button" data-reader="zoom-in" aria-label="{{ __('Zoom in') }}" class="{{ $iconButton }}"><flux:icon name="magnifying-glass-plus" variant="outline" class="size-6" /></button>
                <button type="button" data-reader="fit-width" class="{{ $textButton }} ms-2 px-3">{{ __('Fit width') }}</button>
            </div>
        @endif

        @if ($format === 'pages' || $format === 'webtoon')
            @if ($format === 'pages')
                <button
                    type="button"
                    data-reader="direction"
                    aria-haspopup="dialog"
                    aria-controls="reader-settings"
                    @click="panel === 'settings' ? (panel = null) : open('settings', $el)"
                    class="focus-ring inline-flex min-h-11 items-center rounded-full max-sm:hidden"
                >
                    <span class="inline-flex items-center gap-2 rounded-full bg-surface-input px-3 py-1.5 text-legal text-ink">
                        <flux:icon name="arrows-right-left" variant="outline" class="size-4" />
                        <span data-reader="direction-label" x-text="rtl ? strings.rtl : strings.ltr">{{ $chipLabel }}</span>
                    </span>
                </button>
            @else
                <span class="inline-flex items-center gap-2 rounded-full bg-surface-input px-3 py-1.5 text-legal text-ink max-sm:hidden">
                    <flux:icon name="arrows-right-left" variant="outline" class="size-4" />
                    <span data-reader="direction-label">{{ $chipLabel }}</span>
                </span>
            @endif
        @endif

        {{-- Contents: the book's chapters. On a phone every format has the button (the signed-off phone frames), on a desktop an EPUB does, and a PDF when it has an outline. --}}
        <button
            type="button"
            id="reader-contents-button"
            data-reader="contents-button"
            aria-label="{{ __('Contents') }}"
            aria-haspopup="dialog"
            aria-controls="reader-contents"
            aria-expanded="false"
            :aria-expanded="panel === 'contents'"
            @click="panel === 'contents' ? (panel = null) : open('contents', $el)"
            x-show="hasContents"
            @if ($format === 'pdf') style="display: none" @endif
            @class([$iconButton, 'lg:hidden' => ! in_array($format, ['epub', 'pdf'], true)])
        ><flux:icon name="list-bullet" variant="outline" class="size-6" /></button>

        @if ($format !== 'pdf')
            <button
                type="button"
                id="reader-settings-button"
                data-reader="settings-button"
                aria-label="{{ __('Reading settings') }}"
                aria-haspopup="dialog"
                aria-controls="reader-settings"
                aria-expanded="false"
                :aria-expanded="panel === 'settings'"
                @click="panel === 'settings' ? (panel = null) : open('settings', $el)"
                class="{{ $textButton }}"
            ><span aria-hidden="true">Aa</span></button>
        @endif

        <button type="button" data-reader="fullscreen" aria-label="{{ __('Full screen') }}" aria-pressed="false" :aria-pressed="fullscreen" x-show="canFullscreen" style="display: none" @click="toggleFullscreen()" class="{{ $iconButton }} max-lg:hidden">
            <flux:icon x-show="!fullscreen" name="arrows-pointing-out" variant="outline" class="size-6" />
            <flux:icon x-show="fullscreen" style="display: none" name="arrows-pointing-in" variant="outline" class="size-6" />
        </button>
    </div>
</header>

@if ($format === 'pdf')
    {{-- On a phone the zoom floats over the page, above the bottom bar. --}}
    <div data-reader="zoom-float" role="group" aria-label="{{ __('Zoom') }}" :class="chrome || panel ? 'opacity-100' : 'pointer-events-none opacity-0'" class="reader-chrome fixed end-4 bottom-26 z-30 flex items-center rounded-card border border-hairline bg-surface-deep lg:hidden">
        <button type="button" data-reader="zoom-out" aria-label="{{ __('Zoom out') }}" class="{{ $iconButton }}"><flux:icon name="magnifying-glass-minus" variant="outline" class="size-6" /></button>
        <button type="button" data-reader="zoom-in" aria-label="{{ __('Zoom in') }}" class="{{ $iconButton }}"><flux:icon name="magnifying-glass-plus" variant="outline" class="size-6" /></button>
    </div>
@endif
