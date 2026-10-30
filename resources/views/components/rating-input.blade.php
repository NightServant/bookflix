@props(['label' => null, 'hint' => null])

{{--
    The input for a reader's own score: ten values (1 to 10, D-14) drawn as five stars, each star two half-star targets. Bind it with `wire:model`
    to the score (a whole number, or null for none) and listen for `rated` on it (a bubbling event with `{ value }`, null once cleared) to save:
    arrow keys can walk along the stars, so the event comes after a pause, not on every key. The model is written at once, the save waits for the event.
    A radio group after the APG pattern, built by `ratingInput` (resources/js/components.js): arrows move focus and select (wrapping), Home and End
    jump to the ends, one tab stop, Delete clears. Every half is a radio named "7 out of 10"; the stars are decorative. Stars are 44px tall and
    48px wide, each half a 24px wide target. A "Clear" button appears once there is a score. Nothing moves, so reduced motion changes nothing.
    `label` names the group (and shows beside it); `hint` is what the caption says while nothing is chosen. The read-only rating is <x-rating-display>.
--}}
@php
    $label ??= __('Your rating');
    $hint ??= __('Not rated yet');
@endphp

<div
    x-data="ratingInput({ hint: @js($hint), rated: @js(__('Rated :score out of 10')), cleared: @js(__('Rating cleared')) })"
    x-modelable="value"
    {{ $attributes->class('flex flex-wrap items-center gap-x-3 gap-y-1') }}
>
    <span class="text-meta text-ink-subtle">{{ $label }}</span>

    <div
        role="radiogroup"
        aria-label="{{ $label }}"
        class="flex"
        x-on:keydown="keydown($event)"
        x-on:focusout="leave($event)"
        x-on:pointerleave="preview = null"
    >
        @foreach (range(1, 5) as $star)
            <div
                role="none"
                wire:key="rating-star-{{ $star }}"
                class="relative h-11 w-12 shrink-0 rounded-control has-focus-visible:ring-2 has-focus-visible:ring-accent-electric has-focus-visible:ring-offset-2 has-focus-visible:ring-offset-canvas"
            >
                <span aria-hidden="true" class="pointer-events-none absolute inset-0 flex items-center justify-center">
                    <span class="relative inline-block size-7">
                        <flux:icon name="star" variant="solid" class="size-7 text-ink-muted" />
                        <span class="absolute inset-y-0 start-0 w-0 overflow-hidden" x-bind:style="{ width: fill({{ $star }}) + '%' }">
                            <flux:icon name="star" variant="solid" class="size-7 text-ink" />
                        </span>
                    </span>
                </span>

                @foreach ([$star * 2 - 1, $star * 2] as $score)
                    <button
                        type="button"
                        role="radio"
                        wire:key="rating-score-{{ $score }}"
                        data-value="{{ $score }}"
                        aria-label="{{ __(':score out of 10', ['score' => $score]) }}"
                        x-bind:aria-checked="isChecked({{ $score }})"
                        x-bind:tabindex="tabindex({{ $score }})"
                        x-on:click="choose({{ $score }})"
                        x-on:pointerenter="hover($event, {{ $score }})"
                        class="absolute inset-y-0 w-1/2 cursor-pointer outline-hidden {{ $score % 2 === 1 ? 'start-0' : 'end-0' }}"
                    ></button>
                @endforeach
            </div>
        @endforeach
    </div>

    <span aria-hidden="true" x-text="caption" class="min-w-20 text-meta tabular-nums text-ink-subtle">{{ $hint }}</span>

    <button
        type="button"
        x-show="score !== null"
        x-cloak
        x-on:click="clear(); $root.querySelector('[role=radio]').focus()"
        aria-label="{{ __('Clear your rating') }}"
        class="focus-ring inline-flex min-h-11 items-center gap-1 rounded-control px-3 text-meta text-link hover:underline"
    >
        <flux:icon name="x-mark" variant="micro" class="size-4" />
        <span>{{ __('Clear') }}</span>
    </button>

    {{-- Says what was saved; the stars themselves are named by their radios. --}}
    <span role="status" class="sr-only" x-text="announcement"></span>
</div>
