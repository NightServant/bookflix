@props(['items' => []])

{{--
    Step three's preview: a popularity-ranked row, the real top of the catalog. Each bar is that story's popularity score, stretched
    over the five shown so the order reads at a glance. Decorative, so no links.
--}}
@php
    $rows = array_slice($items, 0, 5);
    $scores = array_column($rows, 'popularity');
    [$low, $high] = $scores ? [min($scores), max($scores)] : [0, 0];
@endphp

<div class="landing-frame">
    <div class="landing-frame__bar"><span>{{ __('Trending now') }}</span><span class="tabular-nums">3 / 4</span></div>

    <div class="landing-ranked">
        @foreach ($rows as $index => $item)
            <div class="landing-ranked__row">
                <span class="landing-ranked__rank">{{ $index + 1 }}</span>
                <img src="{{ $item['cover'] }}" width="64" height="96" alt="" loading="lazy" decoding="async" draggable="false">
                <div class="min-w-0">
                    <p class="truncate text-body font-medium text-ink">{{ $item['title'] }}</p>
                    <div class="landing-ranked__bar"><span style="width: {{ $high > $low ? round(48 + 52 * ($item['popularity'] - $low) / ($high - $low)) : 100 }}%"></span></div>
                </div>
            </div>
        @endforeach
    </div>
</div>
