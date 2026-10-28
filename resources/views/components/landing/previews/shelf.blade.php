@props(['covers' => []])

{{-- Step one's preview: the library as a shelf, two rows of real CSS books and the format switch above them. Decorative, so no links. --}}
<div class="landing-frame">
    <div class="landing-frame__bar"><span>{{ __('Library') }}</span><span class="tabular-nums">1 / 4</span></div>

    <div class="flex flex-wrap gap-2">
        <x-chip :active="true">{{ __('All') }}</x-chip>
        @foreach ([__('Manga'), __('Comics'), __('Novels'), __('Webcomics')] as $format)
            <x-chip>{{ $format }}</x-chip>
        @endforeach
    </div>

    <div class="landing-shelf">
        @foreach (array_chunk(array_slice($covers, 0, 8), 4) as $row)
            <div class="landing-shelf__row">
                @foreach ($row as $cover)
                    <x-book-3d :src="$cover['cover']" :title="$cover['title']" tilt="-16deg" lazy style="--w: 19cqw" />
                @endforeach
            </div>
        @endforeach
    </div>
</div>
