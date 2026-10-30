<?php

use App\Enums\ReadingStatus;
use App\Models\LibraryEntry;
use App\Models\ReadingProgress;
use App\Models\ReadingSession;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('History')] class extends Component {
    use WithPagination;

    private const PER_PAGE = 25;

    /**
     * The header is a band across the whole page, so the layout leaves `<main>` bare (see layouts/app).
     */
    public function render(): View
    {
        return $this->view()->layoutData(['flush' => true]);
    }

    /**
     * The reader's logged sessions, the latest first, with their stories (a story behind the mature gate, or deleted or merged away, is left out).
     * A page number past the end shows the last page.
     *
     * @return LengthAwarePaginator<int, ReadingSession>
     */
    #[Computed]
    public function sessions(): LengthAwarePaginator
    {
        $user = auth()->user();

        $fetch = fn (): LengthAwarePaginator => ReadingSession::query()
            ->where('user_id', $user->id)
            ->visibleTo($user)
            ->with('media')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        $page = $fetch();

        if ($page->isEmpty() && $page->currentPage() > 1 && $page->total() > 0) {
            $this->setPage($page->lastPage());
            $page = $fetch();
        }

        return $page;
    }

    /**
     * The page's sessions by the day they began, the latest day first, as `[Y-m-d => sessions]`.
     *
     * @return Collection<string, Collection<int, ReadingSession>>
     */
    #[Computed]
    public function days(): Collection
    {
        return $this->sessions->getCollection()->groupBy(fn (ReadingSession $session): string => $session->started_at->toDateString());
    }

    /**
     * The figures above the list, from the same sessions the list holds: all the time read, the sessions begun this month, and the stories
     * the reader has completed (of the stories they may see).
     *
     * @return array{seconds: int, month: int, completed: int}
     */
    #[Computed]
    public function figures(): array
    {
        $user = auth()->user();

        $sessions = ReadingSession::query()
            ->where('user_id', $user->id)
            ->visibleTo($user)
            ->toBase()
            ->selectRaw('coalesce(sum(seconds), 0) as seconds, count(*) filter (where started_at >= ?) as month', [now()->startOfMonth()])
            ->first();

        return [
            'seconds' => (int) $sessions->seconds,
            'month' => (int) $sessions->month,
            'completed' => LibraryEntry::query()->where('user_id', $user->id)->where('status', ReadingStatus::Completed)->visibleTo($user)->count(),
        ];
    }

    /**
     * What a day is called: "Today", "Yesterday", the weekday and date within this year, and the full date before it.
     */
    public function dayLabel(string $date): string
    {
        $day = Carbon::parse($date);

        return match (true) {
            $day->isToday() => __('Today'),
            $day->isYesterday() => __('Yesterday'),
            $day->isSameYear(now()) => $day->isoFormat('dddd, MMMM D'),
            default => $day->isoFormat('MMMM D, YYYY'),
        };
    }
}; ?>

@php
    $sessions = $this->sessions;
    $figures = $this->figures;
@endphp

<div class="pb-12">
    <header class="bg-surface-deep px-gutter py-8 lg:py-12">
        <x-page-header :title="__('History')" :description="__('Every stretch of reading you have logged, the latest first, with how long it took and how far it moved you. Log one from any story.')" />

        <x-library-tabs current="history.index" class="mt-6" />
    </header>

    <div class="px-gutter">
        @if ($sessions->isNotEmpty())
            {{-- A line of figures cut by hairlines (never three cards): on a phone one row each, from the sm width one line. --}}
            <dl class="mt-8 flex flex-col divide-y divide-hairline border-y border-hairline sm:w-fit sm:flex-row sm:divide-x sm:divide-y-0 sm:border-y-0">
                @foreach ([
                    __('Total reading time') => ReadingSession::duration($figures['seconds']),
                    __('Sessions this month') => number_format($figures['month']),
                    __('Stories completed') => number_format($figures['completed']),
                ] as $label => $value)
                    <div wire:key="figure-{{ $loop->index }}" class="flex items-baseline justify-between gap-4 py-4 sm:flex-col sm:items-start sm:gap-1 sm:px-10 sm:py-1 sm:first:ps-0 sm:last:pe-0">
                        <dt class="text-meta text-ink-subtle">{{ $label }}</dt>
                        <dd class="text-section tabular-nums text-ink">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            {{-- The list keeps to a reading width, so each session's track stays near its title. --}}
            <div class="max-w-3xl">
                <div wire:loading.delay.shortest.class="opacity-60" class="mt-10 flex flex-col gap-10 motion-safe:transition-opacity">
                    @foreach ($this->days as $date => $day)
                        <section wire:key="day-{{ $date }}" data-day="{{ $date }}" aria-labelledby="day-{{ $date }}">
                            <h2 id="day-{{ $date }}" class="border-b border-hairline pb-3 text-lead text-ink"><time datetime="{{ $date }}">{{ $this->dayLabel($date) }}</time></h2>

                            <ul class="divide-y divide-hairline">
                                @foreach ($day as $session)
                                    @php
                                        $media = $session->media;
                                        $from = (float) $session->from_percent;
                                        $to = (float) $session->to_percent;
                                        // The stretch the session covered on a 0 to 100 track; a sliver is still drawn for a tiny one.
                                        $start = max(0, min(100, min($from, $to)));
                                        $span = max(2, min(100 - $start, abs($to - $from)));
                                    @endphp

                                    <li wire:key="session-{{ $session->id }}" data-session class="flex items-center gap-4 py-3 sm:gap-6">
                                        <a href="{{ route('stories.show', $media->slug) }}" wire:navigate aria-hidden="true" tabindex="-1" class="block aspect-[2/3] w-12 shrink-0 overflow-hidden rounded-control bg-surface-card">
                                            @if ($media->cover_url)
                                                <img src="{{ $media->cover_url }}" alt="" width="48" height="72" loading="lazy" decoding="async" class="size-full object-cover">
                                            @else
                                                <x-cover-fallback :title="$media->title" compact />
                                            @endif
                                        </a>

                                        <div class="min-w-0 flex-1">
                                            <a href="{{ route('stories.show', $media->slug) }}" wire:navigate data-title class="focus-ring inline-flex min-h-11 max-w-full items-center rounded-control text-body text-ink hover:underline"><span class="truncate">{{ $media->title }}</span></a>

                                            <p class="flex flex-wrap items-center gap-x-4 gap-y-0.5 text-meta tabular-nums text-ink-subtle">
                                                <span>
                                                    {{ ReadingProgress::label($from) }}% <span aria-hidden="true">→</span><span class="sr-only"> {{ __('to') }} </span> {{ ReadingProgress::label($to) }}%
                                                </span>
                                                @if ($session->seconds > 0)
                                                    <span class="inline-flex items-center gap-1"><flux:icon name="clock" variant="micro" class="size-4" />{{ ReadingSession::duration($session->seconds) }}</span>
                                                @endif
                                            </p>
                                        </div>

                                        <span aria-hidden="true" class="relative hidden h-1 w-40 shrink-0 bg-surface-input sm:block">
                                            <span class="absolute inset-y-0 bg-ink" style="inset-inline-start: {{ $start }}%; width: {{ $span }}%"></span>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endforeach
                </div>

                <x-pager :paginator="$sessions" class="mt-8" />
            </div>
        @else
            <x-empty-state :title="__('No reading history yet')" class="mt-12">
                <p class="mt-2 text-body text-ink-subtle">{{ __('Log your progress on a story and each stretch of reading lands here, with how long it took and how far it moved you.') }}</p>

                <div class="mt-6 flex flex-wrap gap-3">
                    <x-cta icon="book-open" :href="route('library.index')" wire:navigate>{{ __('Open your library') }}</x-cta>
                    <x-cta variant="secondary" icon="compass" :href="route('discover')" wire:navigate>{{ __('Browse Discover') }}</x-cta>
                </div>
            </x-empty-state>
        @endif
    </div>
</div>
