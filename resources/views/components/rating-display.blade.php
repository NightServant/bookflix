@props([
    'average' => null,
    'count' => 0,
])

{{--
    The read-only rating: `average` is `media.average_rating` (0-5, the blend of every source's average; a decimal or its string) drawn as
    five stars in half-star steps (D-14), with `count` (`media.ratings_count`) beside it when there is one. Screen readers get the sentence
    ("Rated 4.5 out of 5 from 1,234 ratings"), never the stars. A source can give an average and no count (MangaDex): the average is shown as
    it is, with no count. Only when there is no average does it say "No ratings yet". The input that writes a reader's own rating (a score of
    1 to 10, shown on the same five stars) is <x-rating-input>.
--}}
@php
    $count = (int) $count;
    $rated = filled($average) && ((float) $average > 0 || $count > 0);
    $score = $rated ? max(0, min(5, (float) $average)) : 0;
    $halves = (int) round($score * 2);
@endphp

@if ($rated)
    <p {{ $attributes->class('inline-flex items-center gap-2') }}>
        <span class="sr-only">{{ $count > 0 ? trans_choice('Rated :average out of 5 from :total rating|Rated :average out of 5 from :total ratings', $count, ['average' => number_format($score, 1), 'total' => number_format($count)]) : __('Rated :average out of 5', ['average' => number_format($score, 1)]) }}</span>

        <span aria-hidden="true" class="inline-flex gap-0.5">
            @foreach (range(1, 5) as $star)
                @php $fill = $halves >= $star * 2 ? 'full' : ($halves === $star * 2 - 1 ? 'half' : 'empty'); @endphp
                <span data-star="{{ $fill }}" class="relative inline-block size-5">
                    <flux:icon name="star" variant="solid" class="size-5 text-hairline" />
                    @if ($fill !== 'empty')
                        <span class="absolute inset-y-0 start-0 overflow-hidden {{ $fill === 'half' ? 'w-1/2' : 'w-full' }}">
                            <flux:icon name="star" variant="solid" class="size-5 text-ink" />
                        </span>
                    @endif
                </span>
            @endforeach
        </span>

        <span aria-hidden="true" class="text-meta tabular-nums text-ink-subtle">{{ number_format($score, 1) }}@if ($count > 0) ({{ number_format($count) }})@endif</span>
    </p>
@else
    <p {{ $attributes->class('text-meta text-ink-subtle') }}>{{ __('No ratings yet') }}</p>
@endif
