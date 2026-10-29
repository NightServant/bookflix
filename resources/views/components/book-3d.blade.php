@props([
    'media' => null,
    'src' => null,
    'size' => 'md',
    'tilt' => '-18deg',
    'interactive' => false,
    'scene' => false,
    'sceneSrc' => null,
    'bind' => null,
    'title' => null,
    'alt' => '',
    'priority' => false,
    'lazy' => false,
])

{{--
    A CSS-only 3D book (no WebGL): the real cover on the front face, a spine that darkens the cover's edge, a recessed page block
    with `--color-paper` fore-edge stripes and a soft contact shadow. Pass a `:media` (its cover and title) or a `src`.
    `size` is sm|md|lg|xl (the width: 5, 8, 12 and 17rem; a book is always 2:3 and as thick as 20% of its width). `tilt` is the resting
    yaw (-18deg turns the fore-edge towards the reader; a positive angle shows the spine). `interactive` leans the book towards the
    pointer, up to 10deg, on fine pointers only; with reduced motion it stays at its resting angle. A missing or broken cover shows
    <x-cover-fallback> on the front face. `scene` progressively upgrades it to a three.js book (resources/js/book-scene.js) on
    capable devices: the CSS book renders first and stays as the fallback. The scene reads the cover through the same-origin proxy (`covers.show`); a book made from a `src` (no media) passes that proxy path as `sceneSrc`, or its scene would read the remote URL, which WebGL cannot texture. `priority` marks the cover as the page's LCP image (`fetchpriority=high`); `lazy` loads a book below the fold when it nears the viewport. The cover is decorative (`alt` is empty unless set): the title is always written out next to it.
    `bind` is an Alpine expression for the cover URL that follows state (the hero's active slide): it drives the front image and
    `--cover` (so the spine and back follow). The scene is turned separately, by a `book-scene:turn` event.
    The geometry and the lighting are in resources/css/app.css (`.book3d`); resources/js/book-3d.js drives the lean and the fallback.
--}}
@php
    $size = in_array($size, ['sm', 'md', 'lg', 'xl'], true) ? $size : 'md';
    $tilt = preg_match('/^-?\d+(\.\d+)?deg$/', (string) $tilt) ? $tilt : '-18deg';
    $cover = $src ?? $media?->cover_url;
    $title ??= $media?->title ?? '';
    $sceneCover = $scene && $cover ? ($sceneSrc ?? ($media?->exists && $src === null ? $media->coverProxyUrl() : $src)) : null;
    $style = '--tilt: '.$tilt.';'.($cover ? ' --cover: url("'.addcslashes($cover, '"\\').'");' : '');
@endphp

<span
    data-book-3d
    data-size="{{ $size }}"
    {{ $cover ? '' : 'data-broken' }}
    x-data="book3d(@js((bool) $interactive))"
    @if ($bind) x-bind:style="{ '--cover': 'url(' + JSON.stringify({{ $bind }}) + ')' }" @endif
    @if ($sceneCover) data-scene-cover="{{ $sceneCover }}" x-book-scene @endif
    {{ $attributes->class('book3d')->merge(['style' => $style]) }}
>
    <span class="book3d__shadow"></span>

    <span class="book3d__body" x-ref="body">
        <span class="book3d__face book3d__front">
            @if ($cover)
                <img x-ref="cover" src="{{ $cover }}" width="{{ $px = ["sm" => 80, "md" => 128, "lg" => 192, "xl" => 272][$size] }}" height="{{ $px * 1.5 }}" @if ($priority) fetchpriority="high" @elseif ($lazy) loading="lazy" @endif @if ($bind) x-bind:src="{{ $bind }}" @endif alt="{{ $alt }}" decoding="async" draggable="false" x-on:error="broken()">
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
