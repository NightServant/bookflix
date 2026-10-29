@props(['title', 'name' => null, 'open' => false])

{{--
    A disclosure built on <details>. Give siblings the same `name` and only one stays open (native exclusive group).
    The panel collapses with Alpine's x-collapse; with reduced motion it snaps.
--}}
<details
    @if ($name) name="{{ $name }}" @endif
    @if ($open) open @endif
    x-data="accordion(@js((bool) $open))"
    @toggle="sync()"
    {{ $attributes->class('border-b border-hairline') }}
>
    <summary
        @click.prevent="toggle()"
        class="focus-ring flex min-h-11 cursor-pointer list-none items-center justify-between gap-4 rounded-control py-3 text-lead text-ink [&::-webkit-details-marker]:hidden"
    >
        <span>{{ $title }}</span>
        <flux:icon
            name="chevron-down"
            :class="'size-5 shrink-0 text-ink-subtle motion-safe:transition-transform motion-safe:duration-(--motion-fast) motion-safe:ease-out-bookflix'.($open ? ' rotate-180' : '')"
            x-bind:class="{ 'rotate-180': expanded }"
        />
    </summary>

    <div x-show="expanded" x-collapse class="motion-reduce:transition-none! pb-4 text-body text-ink-subtle">
        {{ $slot }}
    </div>
</details>
