@props([
    'media' => null,
    'src' => null,
    'size' => 'md',
    'tilt' => '-18deg',
    'interactive' => false,
    'title' => null,
    'alt' => '',
])

{{--
    A CSS-only 3D book (no WebGL): the real cover on the front face, a spine that darkens the cover's edge, a recessed page block
    with `--color-paper` fore-edge stripes and a soft contact shadow. Pass a `:media` (its cover and title) or a `src`.
    `size` is sm|md|lg|xl (the width: 5, 8, 12 and 17rem; a book is always 2:3 and as thick as 20% of its width). `tilt` is the resting
    yaw (-18deg turns the fore-edge towards the reader; a positive angle shows the spine). `interactive` leans the book towards the
    pointer, up to 10deg, on fine pointers only; with reduced motion it stays at its resting angle. A missing or broken cover shows
    <x-cover-fallback> on the front face. The cover is decorative (`alt` is empty unless set): the title is always written out next to it.
    The geometry and the lighting are in resources/css/app.css (`.book3d`); resources/js/book-3d.js drives the lean and the fallback.
--}}
@php
    $size = in_array($size, ['sm', 'md', 'lg', 'xl'], true) ? $size : 'md';
    $tilt = preg_match('/^-?\d+(\.\d+)?deg$/', (string) $tilt) ? $tilt : '-18deg';
    $cover = $src ?? $media?->cover_url;
    $title ??= $media?->title ?? '';
    $style = '--tilt: '.$tilt.';'.($cover ? ' --cover: url("'.addcslashes($cover, '"\\').'");' : '');
@endphp

<span
    data-book-3d
    data-size="{{ $size }}"
    {{ $cover ? '' : 'data-broken' }}
    x-data="book3d(@js((bool) $interactive))"
    {{ $attributes->class('book3d')->merge(['style' => $style]) }}
>
    <span class="book3d__shadow"></span>

    <span class="book3d__body" x-ref="body">
        <span class="book3d__face book3d__front">
            @if ($cover)
                <img x-ref="cover" src="{{ $cover }}" alt="{{ $alt }}" decoding="async" draggable="false" x-on:error="broken()">
            @endif

            <span class="book3d__fallback" data-book-fallback><x-cover-fallback :title="$title" /></span>
            <span class="book3d__sheen"></span>
        </span>

        <span class="book3d__face book3d__back"></span>
        <span class="book3d__face book3d__spine"></span>

        <span class="book3d__face book3d__case book3d__case-right"></span>
        <span class="book3d__face book3d__case book3d__case-top"></span>
        <span class="book3d__face book3d__case book3d__case-bottom"></span>

        <span class="book3d__face book3d__pages book3d__pages-right"></span>
        <span class="book3d__face book3d__pages book3d__pages-top"></span>
        <span class="book3d__face book3d__pages book3d__pages-bottom"></span>
    </span>
</span>
