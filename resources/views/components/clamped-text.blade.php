@props(['lines' => 4])

{{--
    A paragraph cut to `lines` lines (3 or 4) with a "More" / "Less" disclosure. The button only appears when the text is actually
    cut (it is measured on load and on resize), so a short paragraph stays plain. The whole text is always in the DOM.
--}}
@php
    $clamp = $lines === 3 ? 'line-clamp-3' : 'line-clamp-4';
@endphp

<div
    x-data="{ open: false, cut: false, measure() { if (! this.open) this.cut = this.$refs.text.scrollHeight > this.$refs.text.clientHeight + 1 } }"
    x-init="$nextTick(() => measure())"
    @resize.window.debounce.150ms="measure()"
    {{ $attributes->class('max-w-prose') }}
>
    <p x-ref="text" id="{{ $id = 'clamp-'.Str::random(6) }}" :data-open="open" class="{{ $clamp }} data-[open=true]:line-clamp-none text-body text-ink-subtle text-pretty">{{ $slot }}</p>

    <button
        type="button"
        x-show="cut || open"
        style="display: none"
        @click="open = ! open; $nextTick(() => measure())"
        :aria-expanded="open"
        aria-controls="{{ $id }}"
        class="focus-ring -ms-1 inline-flex min-h-11 items-center rounded-control px-1 text-meta text-link hover:underline"
    ><span x-text="open ? @js(__('Less')) : @js(__('More'))">{{ __('More') }}</span></button>
</div>
