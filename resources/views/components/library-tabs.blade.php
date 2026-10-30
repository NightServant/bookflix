@props(['current' => 'library.index', 'exact' => true])

{{--
    The sections of the reader's own shelf, as tabs under the Library title: the library, its collections and its reading history. They are
    pages of the library, not bar items of their own (the Library item of the primary navigation stays current on all of them). A section
    whose page is not built yet is left out, and a lone Library is no choice at all, so nothing is drawn. `current` is the route name of the page it sits on,
    or of the section it belongs to when it is a page deeper in (one collection, under Collections): then pass `:exact="false"`, and the tab says it is
    the current section (`aria-current="true"`) instead of the current page.
--}}
@php
    $sections = collect([
        'library.index' => __('Library'),
        'collections.index' => __('Collections'),
        'history.index' => __('History'),
    ])->filter(fn (string $label, string $route): bool => Route::has($route));
@endphp

@if ($sections->count() > 1)
    <nav aria-label="{{ __('Library sections') }}" {{ $attributes }}>
        <ul class="flex gap-6 border-b border-hairline">
            @foreach ($sections as $route => $label)
                @php $isCurrent = $route === $current; @endphp
                <li wire:key="library-section-{{ $route }}">
                    <a
                        href="{{ route($route) }}"
                        wire:navigate
                        @if ($isCurrent) aria-current="{{ $exact ? 'page' : 'true' }}" @endif
                        class="focus-ring -mb-px inline-flex min-h-11 items-center border-b-2 px-1 text-body {{ $isCurrent ? 'border-ink text-ink' : 'border-transparent text-ink-subtle hover:text-ink' }}"
                    >{{ $label }}</a>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
