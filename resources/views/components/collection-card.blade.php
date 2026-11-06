@props(['collection', 'preview'])

{{--
    One of the reader's own collections as a card for a shelf row (Home's "Your Collections"): its first covers side by side, the name and the
    number of stories the reader may see. The whole card is the link, a fixed width so a row of them scrolls like the story tiles. `preview` is what
    CollectionPreviews gives (`count`, `covers`). For a list of collections on a page of their own use <x-collection-row>.
--}}
<a
    href="{{ route('collections.show', ['slug' => $collection->slug, 'owner' => $collection->user_id]) }}"
    wire:navigate
    {{ $attributes->class('group focus-ring block w-60 shrink-0 rounded-card bg-surface-card p-3 hover:bg-surface-input sm:w-68') }}
>
    <span aria-hidden="true" class="grid grid-cols-4 gap-1.5">
        @foreach (range(0, 3) as $slot)
            <span class="relative block aspect-[2/3] overflow-hidden rounded-control bg-surface-input group-hover:bg-hairline">
                @if (isset($preview['covers'][$slot]))
                    <img src="{{ $preview['covers'][$slot] }}" alt="" width="64" height="96" loading="lazy" decoding="async" class="size-full object-cover">
                @endif
            </span>
        @endforeach
    </span>

    <span class="mt-3 block truncate text-body font-medium text-ink">{{ $collection->name }}</span>
    <span class="block text-meta tabular-nums text-ink-subtle">{{ $preview['count'] > 0 ? trans_choice(':count story|:count stories', $preview['count']) : __('No stories yet') }}</span>
</a>
