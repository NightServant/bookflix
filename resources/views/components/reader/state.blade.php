@props(['kind', 'backUrl', 'percent' => 0])

@php
    $saved = rtrim(rtrim(number_format((float) $percent, 1, '.', ''), '0'), '.');
    $card = 'w-full max-w-120 rounded-card border border-hairline bg-surface-card p-8 text-center';
    $circle = 'mx-auto flex size-14 items-center justify-center rounded-full bg-surface-input text-ink';
    $primary = 'focus-ring inline-flex min-h-11 items-center justify-center rounded-control bg-ink px-5 text-body font-medium text-canvas hover:bg-ink/90';
    $secondary = 'focus-ring inline-flex min-h-11 items-center justify-center rounded-control bg-surface-input px-5 text-body font-medium text-ink hover:bg-surface-input/80';
@endphp

{{--
    The four states of the reader, one centered card each (frames d08, m08; copy as signed off). Every one keeps "Back to the story". The page shows
    one at a time through its Alpine `state` ('opening', 'corrupt', 'withdrawn', 'password'; an engine's `reader:error` picks it). The primary button
    of a state is white: red is for calls to action, and nothing here is one.
--}}
<div data-reader-state="{{ $kind }}" {{ $attributes->class('flex size-full items-center justify-center p-4') }}>
    @switch($kind)
        @case('opening')
            <div class="{{ $card }}" role="status">
                <span class="{{ $circle }}"><svg class="reader-spinner size-6" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" /><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg></span>
                <p class="mt-5 text-lead text-ink">{{ __('Opening the book…') }}</p>
            </div>
            @break

        @case('corrupt')
            <div class="{{ $card }}" role="alert">
                <span class="{{ $circle }}"><flux:icon name="exclamation-triangle" variant="outline" class="size-6" /></span>
                <h2 class="mt-5 text-lead text-ink">{{ __('This file couldn\'t be opened') }}</h2>
                <p class="mt-2 text-meta text-ink-subtle text-pretty">{{ __('It may be damaged, or in a form Bookflix can\'t read. Your place in the story is saved.') }}</p>
                <div class="mt-6 flex flex-wrap justify-center gap-3">
                    <a href="{{ $backUrl }}" class="{{ $secondary }}">{{ __('Back to the story') }}</a>
                    <button type="button" data-reader="retry" class="{{ $primary }}">{{ __('Try again') }}</button>
                </div>
            </div>
            @break

        @case('withdrawn')
            <div class="{{ $card }}" role="alert">
                <span class="{{ $circle }}"><flux:icon name="lock-closed" variant="outline" class="size-6" /></span>
                <h2 class="mt-5 text-lead text-ink text-balance">{{ __('This book is no longer available to read here') }}</h2>
                {{-- The script rewrites this line with the place it keeps when a save or a fetch is refused mid-read (`data-template`). --}}
                <p data-reader="withdrawn-text" data-template="{{ __('Its file was withdrawn. Your place is saved at :percent%, and the story stays in your library.', ['percent' => ':percent']) }}" class="mt-2 text-meta text-ink-subtle text-pretty">{{ __('Its file was withdrawn. Your place is saved at :percent%, and the story stays in your library.', ['percent' => $saved]) }}</p>
                <div class="mt-6 flex justify-center">
                    <a href="{{ $backUrl }}" class="{{ $secondary }}">{{ __('Back to the story') }}</a>
                </div>
            </div>
            @break

        @case('password')
            <div class="{{ $card }}" role="alert">
                <span class="{{ $circle }}"><flux:icon name="lock-closed" variant="outline" class="size-6" /></span>
                <h2 class="mt-5 text-lead text-ink">{{ __('This PDF is protected by a password') }}</h2>
                <p class="mt-2 text-meta text-ink-subtle text-pretty">{{ __('Bookflix can\'t open password-protected files. Upload a copy without a password.') }}</p>
                <div class="mt-6 flex justify-center">
                    <a href="{{ $backUrl }}" class="{{ $secondary }}">{{ __('Back to the story') }}</a>
                </div>
            </div>
            @break
    @endswitch
</div>
