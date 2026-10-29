@props(['deweyClass'])

@php($ancestors = app(\App\Classification\Dewey\DeweyHierarchy::class)->ancestors($deweyClass))

<nav aria-label="{{ __('Breadcrumb') }}" {{ $attributes }}>
    <ol class="flex flex-nowrap items-center text-meta sm:flex-wrap">
        <li class="flex shrink-0 items-center">
            <a href="{{ route('explore.index') }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center rounded-control text-link hover:underline">{{ __('Explore the Library') }}</a>
        </li>

        {{-- On a phone the middle crumbs collapse to an ellipsis, so the trail stays on one line: root, …, current. --}}
        @if ($ancestors->isNotEmpty())
            <li class="flex items-center sm:hidden" aria-hidden="true">
                <flux:icon.chevron-right variant="micro" class="mx-1 text-ink-muted" />
                <span class="text-ink-subtle">…</span>
            </li>
        @endif

        @foreach ($ancestors as $ancestor)
            <li class="hidden items-center sm:flex">
                <flux:icon.chevron-right variant="micro" class="mx-1 text-ink-muted" />
                <a href="{{ route('explore.show', $ancestor->code) }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center rounded-control text-link hover:underline">{{ $ancestor->code }} {{ $ancestor->name }}</a>
            </li>
        @endforeach

        <li class="flex min-w-0 items-center">
            <flux:icon.chevron-right variant="micro" class="mx-1 shrink-0 text-ink-muted" />
            <span aria-current="page" class="truncate text-ink">{{ $deweyClass->code }} {{ $deweyClass->name }}</span>
        </li>
    </ol>
</nav>
