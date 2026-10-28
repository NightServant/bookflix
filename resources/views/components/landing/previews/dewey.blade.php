@props(['covers' => []])

{{--
    Step two's preview: the Dewey-inspired shelf. Two branches of the real class tree (codes and names are the seeded ones): the one
    that holds Japanese comics opened onto a row of covers, the one that holds Japanese literature left shut. Decorative, so no links.
--}}
@php
    $branches = [
        [['700', __('Arts & Play')], ['740', __('Drawing & Illustration')], ['741.5', __('Comics & Graphic Works')], ['741.52', __('Japanese Comics')]],
        [['800', __('Literature')], ['890', __('Literature in Other Languages')], ['895.6', __('Japanese Literature')]],
    ];
@endphp

<div class="landing-frame">
    <div class="landing-frame__bar"><span>{{ __('Explore the Library') }}</span><span class="tabular-nums">2 / 4</span></div>

    @foreach ($branches as $branch)
        <div @class(['landing-tree', 'landing-tree--shut' => $loop->last])>
            @foreach ($branch as $depth => [$code, $name])
                <p @class(['landing-tree__node', 'landing-tree__node--here' => $loop->last && $loop->parent->first]) style="--d: {{ $depth }}">
                    <span class="landing-tree__code">{{ $code }}</span>
                    <span>{{ $name }}</span>
                </p>
            @endforeach

            @if ($loop->first)
                <div class="landing-tree__covers">
                    @foreach (array_slice($covers, 0, 4) as $cover)
                        <img src="{{ $cover['cover'] }}" width="96" height="144" alt="" loading="lazy" decoding="async" draggable="false">
                    @endforeach
                </div>
            @endif
        </div>
    @endforeach
</div>
