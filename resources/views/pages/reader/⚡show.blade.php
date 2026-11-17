<?php

use App\Enums\ContentFormat;
use App\Enums\ReadingMode;
use App\Models\ContentSource;
use App\Models\Media;
use App\Models\ReadingProgress;
use App\Models\Series;
use App\Reader\Cbz\CbzExtractor;
use App\Reader\Cbz\CbzPageUrl;
use App\Reader\ContentAccessGate;
use App\Reader\ContentUrl;
use App\Reader\SourceChooser;
use App\Reader\Archives\UnsafeArchive;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * The reader: a full-screen page for one file of one story. The server decides what opens (the story must be visible to the reader and one of its
 * sources readable by them, see SourceChooser), the signed address of the file and of a comic's pages (each asked for again, and each one re-checks
 * the access gate: a 403 is what the page turns into "no longer available"), where the reader left off, and which engine runs. The engine (EPUB, PDF,
 * comic pager, webtoon) is the browser's: its container is `wire:ignore` and carries what it needs as `data-` attributes.
 */
new #[Layout('layouts::reader')] class extends Component {
    /** The most comic pages one `pageUrls` call signs. */
    private const MAX_PAGES_PER_CALL = 50;

    #[Locked]
    public int $mediaId;

    #[Locked]
    public int $sourceId;

    /**
     * A merged-away slug answers 301 to the story it became; a story the reader may not see is a 404 (and so is one that is gone); a story with nothing
     * this reader may open sends them back to its page, where "Where to read" says what there is.
     */
    public function mount(string $slug): void
    {
        $user = auth()->user();
        $media = Media::resolveSlug($slug) ?? abort(404);

        if ($media->slug !== $slug) {
            abort(redirect()->route('reader.show', [...request()->query(), 'slug' => $media->slug], 301));
        }

        abort_if($media->isGatedFor($user), 404);

        $requested = request()->query('source');
        $source = app(SourceChooser::class)->for($user, $media, is_string($requested) && ctype_digit($requested) ? (int) $requested : null);

        if ($source === null) {
            abort(redirect()->route('stories.show', $media->slug));
        }

        $this->mediaId = $media->id;
        $this->sourceId = $source->id;
    }

    /**
     * The signed address of the file, 10 minutes (P9-R14: the PDF reader asks again before it runs out).
     */
    #[Renderless]
    public function streamUrl(): string
    {
        return ContentUrl::for(auth()->user(), $this->readable());
    }

    /**
     * Signed addresses for `$count` pages of the comic from page `$from` (at most 50 a call, none beyond the last page), keyed by page number.
     *
     * @return array<int, string>
     */
    #[Renderless]
    public function pageUrls(int $from, int $count): array
    {
        $source = $this->readable();

        abort_unless($source->format === ContentFormat::Cbz, 404);

        $first = max(1, $from);
        $last = min($first + max(0, min($count, self::MAX_PAGES_PER_CALL)) - 1, $this->pageCount ?? 0);
        $urls = [];

        for ($page = $first; $page <= $last; $page++) {
            $urls[$page] = CbzPageUrl::for(auth()->user(), $source, $page);
        }

        return $urls;
    }

    /**
     * The source, once the gate agrees: every call from the browser asks again, so a file withdrawn after the page opened stops at the next call.
     */
    private function readable(): ContentSource
    {
        $source = ContentSource::query()->with('media')->find($this->sourceId);

        abort_unless($source !== null && app(ContentAccessGate::class)->allows(auth()->user(), $source), 403);

        return $source;
    }

    /**
     * How many pages the comic has, or null when its archive can't be read.
     */
    #[Computed]
    public function pageCount(): ?int
    {
        if ($this->source->format !== ContentFormat::Cbz) {
            return null;
        }

        try {
            return app(CbzExtractor::class)->index($this->source)->count();
        } catch (UnsafeArchive) {
            return null;
        }
    }

    #[Computed]
    public function source(): ContentSource
    {
        return ContentSource::query()->findOrFail($this->sourceId);
    }

    #[Computed]
    public function media(): Media
    {
        return Media::query()->with(['mediaType', 'creators', 'series'])->findOrFail($this->mediaId);
    }

    /**
     * Which engine runs: `epub`, `pdf`, `pages` (a comic one page at a time) or `webtoon` (one long strip, by the story type's reading mode).
     */
    #[Computed]
    public function engine(): string
    {
        return match ($this->source->format) {
            ContentFormat::Epub => 'epub',
            ContentFormat::Pdf => 'pdf',
            default => $this->media->mediaType->reading_mode === ReadingMode::VerticalScroll ? 'webtoon' : 'pages',
        };
    }

    /**
     * The way pages turn: manga from Japan right to left, the other paged comics left to right, a webtoon down. Only a start: the reader may change
     * it per story, on their device.
     */
    #[Computed]
    public function direction(): string
    {
        return match (true) {
            $this->engine === 'webtoon' => 'vertical',
            $this->engine === 'pages' && strtoupper((string) $this->media->country_of_origin) === 'JP' => 'rtl',
            default => 'ltr',
        };
    }

    /**
     * Where this reader left off: the locator only when it was saved in this very file (a CFI means nothing in a PDF), always the percent.
     *
     * @return array{locator: array<string, mixed>|null, percent: float, chapter: string|null}
     */
    #[Computed]
    public function resume(): array
    {
        $progress = ReadingProgress::query()->where('user_id', auth()->id())->where('media_id', $this->mediaId)->first();

        return [
            'locator' => $progress !== null && (int) $progress->content_source_id === $this->sourceId ? $progress->locator : null,
            'percent' => $progress === null ? 0.0 : (float) $progress->percent,
            'chapter' => $progress?->chapter,
        ];
    }

    /**
     * The story after this one in its first series, with what the end-of-book card shows, and whether this reader can open it.
     *
     * @return array{media: Media, readable: bool}|null
     */
    #[Computed]
    public function next(): ?array
    {
        $series = $this->media->series->first();

        if (! $series instanceof Series) {
            return null;
        }

        $user = auth()->user();
        $volumes = $series->window($this->media, 1, $user)->values();
        $index = $volumes->search(fn (Media $volume): bool => $volume->is($this->media));
        $next = $index === false ? null : $volumes->get($index + 1);

        if ($next === null) {
            return null;
        }

        $next->load('creators');

        return ['media' => $next, 'readable' => app(SourceChooser::class)->for($user, $next) !== null];
    }

    public function render(): View
    {
        return $this->view()->title($this->media->title);
    }
}; ?>

@php
    $media = $this->media;
    $engine = $this->engine;
    $direction = $this->direction;
    $resume = $this->resume;
    $next = $this->next;
    $percent = $resume['percent'];
    $percentLabel = rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.');
    $backUrl = route('stories.show', $media->slug);
    $paged = in_array($engine, ['epub', 'pages'], true);
    $pageCount = in_array($engine, ['pages', 'webtoon'], true) ? $this->pageCount : null;
    $page = (int) ($resume['locator']['page'] ?? 1);

    // The label a series gives this volume ("Volume 3") leads the subtitle, then where the reader was.
    $volume = $media->series->first()?->pivot->label;
    $subtitle = match ($engine) {
        'pdf' => 'PDF',
        'epub' => $resume['chapter'],
        default => collect([$volume, $resume['chapter']])->filter()->implode(' · '),
    };
    $meta = match ($engine) {
        'pdf' => null,
        'pages', 'webtoon' => $pageCount === null ? null : __('Page :page of :count', ['page' => min($page, $pageCount), 'count' => $pageCount]),
        default => null,
    };

    // What the browser's engine needs, once, on its container.
    $engineData = array_filter([
        'data-source-id' => $this->sourceId,
        'data-format' => $this->source->format->value,
        'data-wire-id' => $this->getId(),
        'data-progress-url' => route('reader.progress', $this->sourceId, absolute: false),
        'data-stream-url' => in_array($engine, ['epub', 'pdf'], true) ? ContentUrl::for(auth()->user(), $this->source) : null,
        'data-resume-locator' => $resume['locator'] === null ? null : json_encode($resume['locator']),
        'data-resume-percent' => $percent,
        'data-direction' => $direction,
        'data-page-count' => $pageCount,
        'data-reading-mode' => $media->mediaType->reading_mode->value,
        'data-strings' => json_encode([
            'chapterOf' => __('Chapter :n of :total', ['n' => ':n', 'total' => ':total']),
            'pageOf' => __('page :n of :total', ['n' => ':n', 'total' => ':total']),
            'pageOfFirst' => __('Page :n of :total', ['n' => ':n', 'total' => ':total']),
            'pageLabel' => __('Page :n', ['n' => ':n']),
            'pdfSubtitle' => __('PDF · :n pages', ['n' => ':n']),
        ]),
    ], fn ($value) => $value !== null);

    // A comic whose archive can't be read opens on the "couldn't open" card at once.
    $initial = in_array($engine, ['pages', 'webtoon'], true) && $pageCount === null ? 'corrupt' : 'opening';

    // The state of the page's Alpine component (resources/js/reader/chrome.js): the numbers it shows before an engine says anything, and its words.
    $chrome = [
        'engine' => $engine,
        'state' => $initial,
        'percent' => $percent,
        'subtitle' => (string) $subtitle,
        'meta' => (string) $meta,
        'direction' => $direction,
        'mediaId' => $this->mediaId,
        'strings' => [
            'read' => __(':percent% read', ['percent' => ':percent']),
            'note' => __(':percent% of the book read', ['percent' => ':percent']),
            'noteIn' => __('You are in :chapter · :percent% of the book read', ['chapter' => ':chapter', 'percent' => ':percent']),
            'next' => __('Next chapter'),
            'last' => __('Last chapter'),
        ],
    ];
@endphp

<div x-data="readerChrome(@js($chrome))" class="fixed inset-0 bg-canvas">
    <x-reader.toolbar :format="$engine" :title="$media->title" :subtitle="$subtitle" :back-url="$backUrl" :direction="$direction" :page-count="$pageCount" :page="$page" />

    <main id="main" tabindex="-1" aria-label="{{ $media->title }}" @class(['absolute inset-x-0 outline-none', 'inset-y-0' => $engine === 'webtoon', 'top-16 bottom-22' => $engine !== 'webtoon'])>
        {{-- Each value is escaped by hand: the locator is the reader's own text, and an attribute bag only backslashes quotes. --}}
        <div wire:ignore data-reader-engine="{{ $engine }}" @foreach ($engineData as $name => $value) {{ $name }}="{{ $value }}" @endforeach class="size-full"></div>

        {{-- One state at a time over the page; the engine's events pick it. --}}
        <div role="presentation" class="absolute inset-0 z-10 bg-canvas" x-show="state !== 'ready'">
            @foreach (['opening', 'corrupt', 'withdrawn', 'password'] as $kind)
                <x-reader.state :kind="$kind" :back-url="$backUrl" :percent="$percent" x-show="state === '{{ $kind }}'" :style="$kind !== $initial ? 'display: none' : null" />
            @endforeach
        </div>

        @if ($engine === 'pages')
            <p aria-hidden="true" class="pointer-events-none absolute inset-x-0 bottom-3 text-center text-meta text-ink-muted md:hidden">
                {{ $direction === 'rtl' ? __('Tap the left side for the next page') : __('Tap the right side for the next page') }}
            </p>
        @endif
    </main>

    @if ($engine === 'webtoon')
        <div aria-hidden="true" class="fixed inset-x-0 top-16 z-40 h-0.75 bg-ink-muted/35"><div data-reader="webtoon-progress" class="h-full bg-ink" style="width: {{ $percentLabel }}%"></div></div>

        <p data-reader="page-pill" role="status" class="fixed bottom-6 start-6 z-30 rounded-full bg-surface-card px-3 py-1.5 text-legal text-ink">{{ $meta }}</p>

        @if ($next)
            <a
                data-reader="next-episode"
                href="{{ $next['readable'] ? route('reader.show', $next['media']->slug) : route('stories.show', $next['media']->slug) }}"
                x-show="finished"
                style="display: none"
                class="focus-ring fixed end-6 bottom-6 z-30 inline-flex min-h-11 items-center gap-2 rounded-control border border-hairline bg-surface-deep ps-4 pe-3 text-body font-medium text-ink hover:bg-surface-card"
            >{{ __('Next episode') }} <flux:icon name="chevron-right" variant="outline" class="size-5" /></a>
        @endif
    @else
        <x-reader.bottom-bar :format="$engine" :percent="$percent" :meta="$meta" :direction="$direction === 'rtl' ? 'rtl' : 'ltr'" />
    @endif

    @if ($paged)
        @php
            // A right-to-left comic turns the other way: the left button goes forward, with a caption.
            $rtl = $direction === 'rtl';
            $sides = [
                'left' => [$rtl ? 'next' : 'prev', 'chevron-left'],
                'right' => [$rtl ? 'prev' : 'next', 'chevron-right'],
            ];
        @endphp
        @foreach ($sides as $side => [$command, $icon])
            <div @class(['reader-chrome fixed top-1/2 z-20 flex -translate-y-1/2 flex-col items-center gap-1 max-md:hidden', 'left-6' => $side === 'left', 'right-6' => $side === 'right']) :class="chrome || panel ? 'opacity-100' : 'pointer-events-none opacity-0'">
                <button
                    type="button"
                    data-reader="{{ $command }}-page"
                    @click="turn({{ $command === 'next' ? 'true' : 'false' }})"
                    aria-label="{{ $command === 'next' ? __('Next page') : __('Previous page') }}"
                    class="focus-ring inline-flex size-11 items-center justify-center rounded-control bg-surface-card/80 text-ink hover:bg-surface-card"
                ><flux:icon :name="$icon" variant="outline" class="size-6" /></button>
                @if ($rtl)
                    <span aria-hidden="true" class="text-legal text-ink-muted">{{ $command === 'next' ? __('Next') : __('Previous') }}</span>
                @endif
            </div>
        @endforeach
    @endif

    <x-reader.chapter-list :percent="$percent" />

    @if ($engine === 'epub')
        <x-reader.settings-panel variant="book" />
    @elseif ($engine === 'pages')
        <x-reader.settings-panel variant="pages" />
    @elseif ($engine === 'webtoon')
        <x-reader.settings-panel variant="strip" />
    @endif

    {{-- The end of the book card: an EPUB or a PDF; a comic ends on its last page and a webtoon on "Next episode". --}}
    @if (in_array($engine, ['epub', 'pdf'], true))
        <x-reader.finished :title="$media->title" :back-url="$backUrl" :next="$next['media'] ?? null" :next-readable="$next['readable'] ?? false" />
    @endif
</div>
