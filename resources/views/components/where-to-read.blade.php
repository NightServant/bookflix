@props(['links', 'media'])

{{--
    "Where to read" (frame d10): the story's verified links out, shown only while Bookflix has no file this reader can open. Each row opens in a new
    tab (noopener noreferrer, and the new tab is announced); the label is the rights holder or, failing that, the site; the line under it is the licence.
    The footer link goes to the upload form with this story chosen, once that form exists.
--}}
<section aria-labelledby="where-to-read" {{ $attributes->class('w-full shrink-0 rounded-card border border-hairline bg-surface-card p-6 lg:w-[23rem] lg:self-start') }}>
    <h2 id="where-to-read" class="text-lead text-ink">{{ __('Where to read') }}</h2>
    <p class="mt-3 text-meta text-ink-subtle">{{ __('Bookflix doesn\'t host this story. These links go to its publishers.') }}</p>

    <ul class="mt-4 flex flex-col gap-3">
        @foreach ($links as $link)
            <li wire:key="read-link-{{ $link->id }}">
                <a href="{{ $link->url }}" target="_blank" rel="noopener noreferrer" class="focus-ring flex min-h-11 items-center justify-between gap-3 rounded-control bg-surface-input px-4 py-3 text-ink hover:bg-surface-input/80">
                    <span class="min-w-0">
                        <span class="block truncate text-body">{{ $link->rights_holder ?: preg_replace('/^www\./', '', (string) parse_url((string) $link->url, PHP_URL_HOST)) }}</span>
                        <span class="block truncate text-meta text-ink-subtle">{{ \Illuminate\Support\Str::limit($link->license, 60) }}</span>
                    </span>
                    <flux:icon name="arrow-top-right-on-square" variant="outline" class="size-6 shrink-0" />
                    <span class="sr-only">{{ __('(opens in a new tab)') }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    @if (Route::has('library.upload'))
        <a href="{{ route('library.upload', ['story' => $media->slug]) }}" class="focus-ring mt-4 inline-flex min-h-11 items-center gap-2 rounded-control text-meta text-link hover:underline">
            <flux:icon name="arrow-up-tray" variant="outline" class="size-5" />
            {{ __('Have a DRM-free copy? Add your own file') }}
        </a>
    @endif
</section>
