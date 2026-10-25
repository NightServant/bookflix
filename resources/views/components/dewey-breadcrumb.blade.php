@props(['deweyClass'])

@php($ancestors = app(\App\Classification\Dewey\DeweyHierarchy::class)->ancestors($deweyClass))

<nav aria-label="{{ __('Breadcrumb') }}" {{ $attributes }}>
    <ol class="flex flex-wrap items-center text-meta">
        <li class="flex items-center">
            <a href="{{ route('explore.index') }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center rounded-control text-link hover:underline">{{ __('Explore the Library') }}</a>
        </li>

        @foreach ($ancestors as $ancestor)
            <li class="flex items-center">
                <flux:icon.chevron-right variant="micro" class="mx-1 text-ink-muted" />
                <a href="{{ route('explore.show', $ancestor->code) }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center rounded-control text-link hover:underline">{{ $ancestor->code }} {{ $ancestor->name }}</a>
            </li>
        @endforeach

        <li class="flex items-center">
            <flux:icon.chevron-right variant="micro" class="mx-1 text-ink-muted" />
            <span aria-current="page" class="text-ink">{{ $deweyClass->code }} {{ $deweyClass->name }}</span>
        </li>
    </ol>
</nav>
