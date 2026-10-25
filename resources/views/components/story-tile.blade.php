@props(['media'])

{{-- ponytail: not a link yet; Phase 4 adds the story page and this becomes a link to it. --}}
<div {{ $attributes }}>
    @if ($media->cover_url)
        <img src="{{ $media->cover_url }}" alt="" loading="lazy" class="aspect-[2/3] w-full rounded-card bg-surface-card object-cover">
    @else
        <div class="aspect-[2/3] w-full rounded-card bg-surface-card" aria-hidden="true"></div>
    @endif
    <p class="mt-2 line-clamp-2 text-meta text-ink">{{ $media->title }}</p>
</div>
