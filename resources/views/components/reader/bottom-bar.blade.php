@props(['format', 'percent' => 0, 'meta' => null, 'direction' => 'ltr'])

@php
    $value = number_format((float) $percent, 1, '.', '');
    $label = fn ($n) => rtrim(rtrim(number_format((float) $n, 1, '.', ''), '0'), '.');
@endphp

{{--
    The bottom bar (88px; not for a webtoon, which has its own progress line). A native range (role slider) drawn by `.reader-range`, then the meta row:
    where the reader is on the left, the percent (or the chapter) and Next chapter on the right. At the end of the book the button reads "Last chapter"
    and is disabled with aria-disabled (the script flips it); a right-to-left comic fills the track from the right.
--}}
<div data-reader="bottom-bar" {{ $attributes->class('reader-chrome fixed inset-x-0 bottom-0 z-40 h-22 border-t border-hairline bg-surface-deep/92 px-4 lg:px-6') }} :class="chrome || panel ? 'opacity-100' : 'pointer-events-none opacity-0'">
    <input
        type="range"
        data-reader="progress"
        min="0"
        max="100"
        step="0.1"
        value="{{ $value }}"
        @if ($direction === 'rtl') dir="rtl" @endif
        aria-label="{{ __('Reading progress') }}"
        aria-valuetext="{{ __(':percent% read', ['percent' => $label($percent)]) }}"
        style="--reader-progress: {{ $label($percent) }}%"
        :value="percent"
        :style="'--reader-progress: ' + percent + '%'"
        :aria-valuetext="valueText"
        @input="scrub($event.target.value)"
        @change="seek($event.target.value)"
        class="reader-range"
    >

    <div class="flex items-center justify-between gap-4">
        <p data-reader="meta" x-text="meta" class="min-w-0 truncate text-meta text-ink-muted">{{ $meta }}</p>

        <div class="flex shrink-0 items-center gap-4">
            <p data-reader="percent" x-text="percentLabel" class="text-meta font-medium text-ink">{{ $label($percent) }}%</p>

            @if ($format !== 'pdf')
                <button
                    type="button"
                    data-reader="next-chapter"
                    @click="nextChapter()"
                    :aria-disabled="lastChapter ? 'true' : null"
                    class="focus-ring inline-flex min-h-11 items-center gap-1 rounded-control bg-surface-card ps-3 pe-2 text-meta font-medium text-ink hover:bg-surface-input aria-disabled:pointer-events-none aria-disabled:opacity-40 max-sm:hidden"
                >
                    <span data-reader="next-chapter-label" x-text="lastChapter ? strings.last : strings.next">{{ __('Next chapter') }}</span>
                    <flux:icon name="chevron-right" variant="outline" class="size-4" />
                </button>
            @endif
        </div>
    </div>
</div>
