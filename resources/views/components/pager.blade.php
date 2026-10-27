@props(['paginator'])

{{--
    The one pager: Previous and Next at the 44px target, and the place in between ("Page 2 of 40"; a simple paginator, which does not
    know the total, says "Page 2"). It never repeats the result count, which the page already shows above its list. For a Livewire
    page that uses WithPagination. Renders nothing for a single page.
--}}
@php
    $known = $paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator;
    $button = 'focus-ring inline-flex min-h-11 items-center gap-2 rounded-control bg-surface-input px-4 text-meta font-medium text-ink transition-colors hover:bg-hairline';
    $off = 'inline-flex min-h-11 items-center gap-2 rounded-control px-4 text-meta font-medium text-ink-muted';
@endphp

@if ($paginator->hasPages())
    <nav aria-label="{{ __('Pagination') }}" data-pager {{ $attributes->class('flex items-center justify-center gap-3 sm:gap-6') }}>
        @if ($paginator->onFirstPage())
            <span aria-disabled="true" class="{{ $off }}"><flux:icon name="arrow-left" variant="micro" class="size-4" />{{ __('Previous') }}</span>
        @else
            <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" class="{{ $button }}"><flux:icon name="arrow-left" variant="micro" class="size-4" />{{ __('Previous') }}</button>
        @endif

        <span role="status" class="min-w-24 text-center text-meta tabular-nums text-ink-subtle">
            {{ $known ? __('Page :page of :last', ['page' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) : __('Page :page', ['page' => $paginator->currentPage()]) }}
        </span>

        @if ($paginator->hasMorePages())
            <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" class="{{ $button }}">{{ __('Next') }}<flux:icon name="arrow-right" variant="micro" class="size-4" /></button>
        @else
            <span aria-disabled="true" class="{{ $off }}">{{ __('Next') }}<flux:icon name="arrow-right" variant="micro" class="size-4" /></span>
        @endif
    </nav>
@endif
