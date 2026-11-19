<?php

use App\Access\MatureAccess;
use App\Discovery\CollectionPreviews;
use App\Enums\ReadingStatus;
use App\Enums\ReviewState;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\ContentSource;
use App\Models\Genre;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\Profile;
use App\Models\Rating;
use App\Models\ReadingSession;
use App\Models\Review;
use App\Models\User;
use App\Models\UserPreference;
use App\Reader\SourceChooser;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The reader's own profile (P10-R19): only its owner ever sees it, so it takes no id. Every story on it passes Media::visibleTo, so a title
 * behind the mature gate is neither listed nor counted. The queries are bounded (see ProfilePageTest's budget): figures are aggregates,
 * lists are capped and eager loaded.
 */
new #[Title('Your profile')] class extends Component {
    private const READING = 3;

    private const COVERS = 7;

    private const REVIEWS = 2;

    private const ALL_REVIEWS = 50;

    private const COLLECTIONS = 3;

    /** The "Show all reviews" footer opens the full list (up to ALL_REVIEWS) in place: there is no page of a reader's reviews. */
    public bool $allReviews = false;

    public function render(): View
    {
        return $this->view()->layoutData(['flush' => true]);
    }

    public function showAllReviews(): void
    {
        $this->allReviews = true;
    }

    private function reader(): User
    {
        return auth()->user();
    }

    #[Computed]
    public function profile(): Profile
    {
        return $this->reader()->profile ?? new Profile(['preferred_languages' => []]);
    }

    /**
     * The library in one query: how many entries at each status, the favourites, and the stories finished this year and last year
     * (with the first and last month of each year's finishes).
     *
     * @return array{statuses: array<string, int>, favorites: int, years: array<int, array{count: int, from: ?Carbon, to: ?Carbon}>}
     */
    #[Computed]
    public function library(): array
    {
        $user = $this->reader();
        $thisYear = now()->startOfYear();
        $lastYear = $thisYear->copy()->subYear();
        $completed = ReadingStatus::Completed->value;

        $selects = [];
        $bindings = [];

        foreach (ReadingStatus::cases() as $status) {
            $selects[] = "count(*) filter (where user_library.status = ?) as status_{$status->value}";
            $bindings[] = $status->value;
        }

        foreach (['this' => [$thisYear, null], 'last' => [$lastYear, $thisYear]] as $key => [$from, $to]) {
            $where = 'user_library.status = ? and user_library.completed_at >= ?'.($to ? ' and user_library.completed_at < ?' : '');
            $range = $to ? [$completed, $from, $to] : [$completed, $from];
            $selects[] = "count(*) filter (where {$where}) as {$key}_count";
            $selects[] = "min(user_library.completed_at) filter (where {$where}) as {$key}_from";
            $selects[] = "max(user_library.completed_at) filter (where {$where}) as {$key}_to";
            array_push($bindings, ...$range, ...$range, ...$range);
        }

        $row = LibraryEntry::query()->where('user_library.user_id', $user->id)->visibleTo($user)->toBase()
            ->selectRaw(implode(', ', [...$selects, 'count(*) filter (where user_library.favorited_at is not null) as favorites']), $bindings)
            ->first();

        $year = fn (string $key): array => [
            'count' => (int) $row->{"{$key}_count"},
            'from' => $row->{"{$key}_from"} ? Carbon::parse($row->{"{$key}_from"}) : null,
            'to' => $row->{"{$key}_to"} ? Carbon::parse($row->{"{$key}_to"}) : null,
        ];

        return [
            'statuses' => collect(ReadingStatus::cases())->mapWithKeys(fn (ReadingStatus $status): array => [$status->value => (int) $row->{"status_{$status->value}"}])->all(),
            'favorites' => (int) $row->favorites,
            'years' => [$thisYear->year => $year('this'), $lastYear->year => $year('last')],
        ];
    }

    /**
     * Time read in all, and the weekly average over the last eight weeks.
     *
     * @return array{total: int, weekly: int}
     */
    #[Computed]
    public function readingTime(): array
    {
        $user = $this->reader();

        $row = ReadingSession::query()->where('user_id', $user->id)->visibleTo($user)->toBase()
            ->selectRaw('coalesce(sum(seconds), 0) as total, coalesce(sum(seconds) filter (where started_at >= ?), 0) as recent', [now()->subWeeks(8)])
            ->first();

        return ['total' => (int) $row->total, 'weekly' => intdiv((int) $row->recent, 8)];
    }

    /**
     * The reader's own reviews of stories they may see: how many, how many this month, and the ones listed (the latest two, or all).
     *
     * @return array{total: int, month: int, list: EloquentCollection<int, Review>}
     */
    #[Computed]
    public function reviews(): array
    {
        $user = $this->reader();
        $mine = fn (): Builder => Review::query()->where('reviews.user_id', $user->id)->whereHas('media', fn (Builder $media) => $media->unmerged()->visibleTo($user));

        $row = $mine()->toBase()->selectRaw('count(*) as total, count(*) filter (where reviews.created_at >= ?) as month', [now()->startOfMonth()])->first();

        return [
            'total' => (int) $row->total,
            'month' => (int) $row->month,
            'list' => $mine()->with(['media.creators'])->orderByDesc('reviews.created_at')->orderByDesc('reviews.id')->limit($this->allReviews ? self::ALL_REVIEWS : self::REVIEWS)->get(),
        ];
    }

    /**
     * The reader's collections, the latest changed first (three of them), how many there are, and how many stories they hold between them.
     *
     * @return array{total: int, stories: int, list: EloquentCollection<int, Collection>, previews: array<int, array{count: int, covers: list<string>}>}
     */
    #[Computed]
    public function collections(): array
    {
        $user = $this->reader();
        $list = $user->collections()->orderByDesc('updated_at')->orderByDesc('id')->limit(self::COLLECTIONS)->get();

        return [
            'total' => $user->collections()->count(),
            'stories' => Media::query()->unmerged()->visibleTo($user)
                ->whereIn('id', CollectionItem::query()->whereIn('collection_id', Collection::query()->where('user_id', $user->id)->select('id'))->select('media_id'))
                ->count(),
            'list' => $list,
            'previews' => app(CollectionPreviews::class)->for($list, $user),
        ];
    }

    /**
     * The genres the reader chose as favourites (in onboarding or Settings, Preferences), mature ones only for a reader who may see them.
     *
     * @return list<string>
     */
    #[Computed]
    public function genres(): array
    {
        $user = $this->reader();

        return Genre::query()
            ->whereIn('id', UserPreference::query()->where('user_id', $user->id)->where('preferable_type', 'genre')->select('preferable_id'))
            ->unless(MatureAccess::allows($user), fn (Builder $genres) => $genres->where('is_mature', false))
            ->orderBy('name')
            ->limit(12)
            ->pluck('name')
            ->all();
    }

    /**
     * Up to three stories the reader is reading, the most recently read first, with their progress and what the button opens: the reader
     * when there is a file this reader may open, else the story page.
     *
     * @return list<array{entry: LibraryEntry, percent: float, read_at: ?Carbon, href: string}>
     */
    #[Computed]
    public function reading(): array
    {
        $user = $this->reader();

        $entries = LibraryEntry::query()
            ->select('user_library.*', 'reading_progress.percent as progress_percent', 'reading_progress.last_read_at as progress_read_at')
            ->leftJoin('reading_progress', fn ($join) => $join->on('reading_progress.media_id', '=', 'user_library.media_id')->on('reading_progress.user_id', '=', 'user_library.user_id'))
            ->where('user_library.user_id', $user->id)
            ->where('user_library.status', ReadingStatus::Reading->value)
            ->visibleTo($user)
            ->orderByRaw('reading_progress.last_read_at desc nulls last')
            ->orderByDesc('user_library.updated_at')
            ->orderByDesc('user_library.id')
            ->limit(self::READING)
            ->with(['media.mediaType', 'media.creators'])
            ->get();

        $sources = ContentSource::query()->whereIn('media_id', $entries->pluck('media_id'))->get()->groupBy('media_id');
        $chooser = app(SourceChooser::class);

        return $entries->map(function (LibraryEntry $entry) use ($user, $sources, $chooser): array {
            $media = $entry->media;
            $readable = $chooser->fromLoaded($user, $media, $sources->get($media->id, new EloquentCollection)) !== null;

            return [
                'entry' => $entry,
                'percent' => (float) ($entry->getAttribute('progress_percent') ?? 0),
                'read_at' => $entry->getAttribute('progress_read_at') ? Carbon::parse($entry->getAttribute('progress_read_at')) : null,
                'href' => $readable ? route('reader.show', $media->slug) : route('stories.show', $media->slug),
            ];
        })->all();
    }

    /**
     * Up to seven of the latest finishes of each of this year and last year (one query: ranked by year).
     *
     * @return array<int, EloquentCollection<int, LibraryEntry>>
     */
    #[Computed]
    public function finished(): array
    {
        $user = $this->reader();
        $from = now()->startOfYear()->subYear();

        $ranked = LibraryEntry::query()
            ->select('user_library.*')
            ->selectRaw('row_number() over (partition by extract(year from user_library.completed_at) order by user_library.completed_at desc, user_library.id desc) as place')
            ->where('user_library.user_id', $user->id)
            ->where('user_library.status', ReadingStatus::Completed->value)
            ->where('user_library.completed_at', '>=', $from)
            ->visibleTo($user);

        return LibraryEntry::query()->fromSub($ranked, 'user_library')
            ->where('place', '<=', self::COVERS)
            ->orderByDesc('completed_at')->orderByDesc('id')
            ->with('media')
            ->get()
            ->groupBy(fn (LibraryEntry $entry): int => $entry->completed_at->year)
            ->all();
    }

    /**
     * The six things that make a fuller profile, each done or with a link to where to do it.
     *
     * @return list<array{label: string, done: bool, href: string}>
     */
    #[Computed]
    public function strength(): array
    {
        $profile = $this->profile;
        $library = route('library.index', ['status' => ReadingStatus::Completed->value]);

        return [
            ['label' => __('Add a photo'), 'done' => filled($profile->avatar_path), 'href' => route('profile.edit')],
            ['label' => __('Write a short bio'), 'done' => filled($profile->bio), 'href' => route('profile.edit')],
            ['label' => __('Choose favourite genres'), 'done' => $this->genres !== [], 'href' => route('preferences.edit')],
            ['label' => __('Rate 5 stories'), 'done' => Rating::query()->where('user_id', $this->reader()->id)->count() >= 5, 'href' => $library],
            ['label' => __('Add a headline'), 'done' => filled($profile->headline), 'href' => route('profile.edit')],
            ['label' => __('Write your first review'), 'done' => $this->reviews['total'] > 0, 'href' => $library],
        ];
    }

    /**
     * "English and Japanese": the reader's languages by name.
     */
    public function languages(): ?string
    {
        $names = array_map(
            fn (string $code): string => class_exists(Locale::class) ? ucfirst((string) Locale::getDisplayLanguage($code, app()->getLocale())) : strtoupper($code),
            $this->profile->preferred_languages ?? [],
        );

        return $names === [] ? null : Arr::join($names, ', ', ' '.__('and').' ');
    }

    public function creatorsLine(Media $media): string
    {
        return $media->creators->sortBy('pivot.position')->take(2)->pluck('name')->implode(', ');
    }

    public function ago(CarbonInterface $when): string
    {
        return $when->diffForHumans(['options' => CarbonInterface::ONE_DAY_WORDS]);
    }
}; ?>

@php
    $user = auth()->user();
    $profile = $this->profile;
    $library = $this->library;
    $time = $this->readingTime;
    $reviews = $this->reviews;
    $collections = $this->collections;
    $strength = $this->strength;
    $done = count(array_filter(array_column($strength, 'done')));
    $level = match (true) { $done >= 6 => __('Complete'), $done >= 3 => __('Good'), default => __('Getting started') };
    [$thisYear, $lastYear] = array_keys($library['years']);
    $finishedNow = $library['years'][$thisYear]['count'];
    $finishedBefore = $library['years'][$lastYear]['count'];
    $languages = $this->languages();

    $card = 'rounded-card border border-hairline bg-surface-deep';
    $footer = 'focus-ring flex min-h-12 w-full items-center justify-center gap-2 rounded-b-card border-t border-hairline px-5 text-meta text-ink-subtle hover:bg-surface-card hover:text-ink';
@endphp

{{-- The app's gutter keeps the page in line with the header; inside it the page stops at 1200px on a very wide screen. --}}
<div class="px-gutter pt-6 pb-12 lg:pt-10">
<div class="mx-auto w-full max-w-[75rem] lg:grid lg:grid-cols-[minmax(0,1fr)_22.5rem] lg:items-start lg:gap-12">
    <div class="flex min-w-0 flex-col gap-4">
        {{-- Header: the banner is the featured gradient, darkened so the card's text never sits on it. --}}
        <section aria-labelledby="profile-name" class="{{ $card }} overflow-hidden">
            <div aria-hidden="true" class="relative h-28 bg-linear-to-r from-featured-from via-featured-via to-featured-to lg:h-49">
                <div class="absolute inset-0 bg-linear-to-b from-canvas/10 to-canvas/50"></div>
            </div>

            <div class="relative px-5 pb-5 sm:px-6 sm:pb-6">
                <div class="-mt-13 flex items-end justify-between gap-4 lg:-mt-19">
                    <flux:avatar
                        :name="$user->name"
                        :initials="$user->initials()"
                        :src="$user->avatarUrl()"
                        class="size-24! rounded-card! text-3xl! ring-4 ring-surface-deep lg:size-36! lg:text-5xl!"
                    />

                    <x-cta variant="secondary" icon="pencil-square" :href="route('profile.edit')" wire:navigate :aria-label="__('Edit profile')" class="bg-transparent! hover:bg-surface-card!" />
                </div>

                <h1 id="profile-name" class="mt-4 text-[1.625rem] leading-tight font-black tracking-tight text-ink lg:text-[2rem]">{{ $user->name }}</h1>
                @if (filled($profile->headline))
                    <p class="mt-1 text-body text-ink">{{ $profile->headline }}</p>
                @endif
                <p class="mt-1 text-meta text-ink-subtle">
                    {{ __('Reader since :date', ['date' => $user->created_at->isoFormat('MMMM YYYY')]) }}@if ($languages) <span aria-hidden="true">·</span> {{ __('Reads in :languages', ['languages' => $languages]) }}@endif
                </p>

                <p class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-surface-card px-3 py-1 text-legal text-ink-subtle">
                    <flux:icon name="lock-closed" variant="micro" class="size-3.5" />
                    {{ __('Only you can see this page') }}
                </p>

                <div class="mt-5 grid grid-cols-2 gap-3 sm:flex sm:flex-wrap">
                    <x-cta variant="light" icon="pencil-square" :href="route('profile.edit')" wire:navigate>{{ __('Edit profile') }}</x-cta>
                    <x-cta variant="secondary" :href="route('privacy.edit').'#export'" wire:navigate class="hidden! sm:inline-flex!">{{ __('Download my data') }}</x-cta>
                    <x-cta variant="secondary" icon="lock-closed" :href="route('privacy.edit')" wire:navigate>{{ __('Privacy') }}</x-cta>
                </div>
            </div>
        </section>

        <div class="lg:hidden">@include('pages.profile.strength', ['where' => 'phone'])</div>

        {{-- Your reading: four figures, one line cut by hairlines on a wide screen, two by two on a phone (never a row of cards). --}}
        <section aria-labelledby="reading-title" class="{{ $card }}">
            <header class="px-5 pt-5 sm:px-6">
                <h2 id="reading-title" class="text-lead text-ink">{{ __('Your reading') }}</h2>
                <p class="mt-1 flex items-center gap-1.5 text-legal text-ink-muted"><flux:icon name="lock-closed" variant="micro" class="size-3.5 shrink-0" />{{ __('Private to you') }}</p>
            </header>

            @php
                $delta = $finishedNow - $finishedBefore;
                $stats = [
                    'finished' => ['icon' => 'book-open', 'value' => number_format($finishedNow), 'label' => __('stories finished this year'), 'sub' => match (true) {
                        $delta > 0 => __(':count more than last year', ['count' => $delta]),
                        $delta < 0 => __(':count fewer than last year', ['count' => -$delta]),
                        default => __('the same as last year'),
                    }],
                    'time' => ['icon' => 'clock', 'value' => ReadingSession::duration($time['total']), 'label' => __('spent reading'), 'sub' => $time['weekly'] > 0
                        ? __('about :time a week', ['time' => ReadingSession::duration($time['weekly'])])
                        : __('nothing logged lately')],
                    'reviews' => ['icon' => 'chat-bubble-left-right', 'value' => number_format($reviews['total']), 'label' => trans_choice('review written|reviews written', $reviews['total']), 'sub' => __(':count this month', ['count' => $reviews['month']])],
                    'collections' => ['icon' => 'rectangle-stack', 'value' => number_format($collections['total']), 'label' => trans_choice('collection|collections', $collections['total']), 'sub' => trans_choice(':count story in them|:count stories in them', $collections['stories'])],
                ];
            @endphp

            <dl class="grid grid-cols-2 gap-y-6 px-5 py-6 sm:px-6 lg:grid-cols-4 lg:gap-y-0 lg:divide-x lg:divide-hairline">
                @foreach ($stats as $key => $stat)
                    {{-- Each stat is a dt and its dds (the icon sits in the figure's dd); the figure shows first, the term under it. --}}
                    <div data-stat="{{ $key }}" class="flex min-w-0 flex-col pe-3 lg:px-5 lg:first:ps-0 lg:last:pe-0">
                        <dt class="order-2 ps-8 text-meta text-ink-subtle">{{ $stat['label'] }}</dt>
                        <dd class="order-1 flex items-center gap-3 text-section tabular-nums text-ink">
                            <flux:icon :name="$stat['icon']" class="size-5 shrink-0 text-ink-subtle" />{{ $stat['value'] }}
                        </dd>
                        <dd class="order-3 mt-0.5 ps-8 text-legal text-ink-muted">{{ $stat['sub'] }}</dd>
                    </div>
                @endforeach
            </dl>

            <a href="{{ route('history.index') }}" wire:navigate class="{{ $footer }}">{{ __('Show your reading history') }}<flux:icon name="arrow-right" variant="micro" class="size-4" /></a>
        </section>

        <section aria-labelledby="about-title" class="{{ $card }} p-5 sm:p-6">
            <header class="flex items-start justify-between gap-4">
                <h2 id="about-title" class="text-lead text-ink">{{ __('About') }}</h2>
                <x-cta variant="secondary" icon="pencil-square" :href="route('profile.edit')" wire:navigate :aria-label="__('Edit your bio')" class="-mt-2 -me-2 bg-transparent! hover:bg-surface-card!" />
            </header>

            @if (filled($profile->bio))
                <p class="mt-3 max-w-prose text-body whitespace-pre-line text-ink">{{ $profile->bio }}</p>
            @else
                <p class="mt-3 text-body text-ink-subtle">
                    {{ __('Add a few lines about what you like to read.') }}
                    <a href="{{ route('profile.edit') }}" wire:navigate class="focus-ring rounded-control text-link hover:underline">{{ __('Profile settings') }}</a>
                </p>
            @endif

            <h3 class="mt-5 text-meta text-ink-subtle">{{ __('Favourite genres') }}</h3>
            @if ($this->genres !== [])
                <ul class="mt-2 flex flex-wrap gap-2">
                    @foreach ($this->genres as $genre)
                        <li wire:key="genre-{{ $loop->index }}" class="rounded-full border border-hairline px-3 py-1.5 text-meta text-ink">{{ $genre }}</li>
                    @endforeach
                </ul>
            @else
                <p class="mt-2 text-meta text-ink-subtle">
                    <a href="{{ route('preferences.edit') }}" wire:navigate class="focus-ring inline-flex min-h-11 items-center rounded-control text-link hover:underline">{{ __('Choose the genres you like') }}</a>
                </p>
            @endif
        </section>

        <section aria-labelledby="current-title" data-section="reading" class="{{ $card }} p-5 sm:p-6">
            <h2 id="current-title" class="text-lead text-ink">{{ __('Currently reading') }}</h2>

            @if ($this->reading === [])
                <p class="mt-3 text-body text-ink-subtle">
                    {{ __('Nothing on the go.') }}
                    <a href="{{ route('discover') }}" wire:navigate class="focus-ring rounded-control text-link hover:underline">{{ __('Find something on Discover.') }}</a>
                </p>
            @else
                <ul class="mt-2 divide-y divide-hairline">
                    @foreach ($this->reading as $row)
                        @php $media = $row['entry']->media; @endphp
                        <li wire:key="reading-{{ $row['entry']->id }}" class="flex flex-wrap items-center gap-4 py-4 last:pb-0 sm:flex-nowrap">
                            <a href="{{ route('stories.show', $media->slug) }}" wire:navigate aria-hidden="true" tabindex="-1" class="block aspect-[2/3] w-14 shrink-0 overflow-hidden rounded-control bg-surface-card">
                                @if ($media->cover_url)
                                    <img src="{{ $media->cover_url }}" alt="" width="56" height="84" loading="lazy" decoding="async" class="size-full object-cover">
                                @else
                                    <x-cover-fallback :title="$media->title" compact />
                                @endif
                            </a>

                            <div class="min-w-0 flex-1">
                                <a href="{{ route('stories.show', $media->slug) }}" wire:navigate class="focus-ring inline-flex max-w-full rounded-control text-body text-ink hover:underline"><span class="truncate">{{ $media->title }}</span></a>
                                <p class="truncate text-meta text-ink-subtle">{{ collect([$this->creatorsLine($media), $media->mediaType?->name])->filter()->implode(' · ') }}</p>
                                <div aria-hidden="true" class="mt-2 h-1 w-full max-w-60 rounded-full bg-surface-card">
                                    <div class="h-1 rounded-full bg-ink" style="width: {{ max(0, min(100, $row['percent'])) }}%"></div>
                                </div>
                                <p class="mt-1 text-legal tabular-nums text-ink-muted">
                                    @if ($row['read_at'])
                                        {{ App\Models\ReadingProgress::label(floor($row['percent'])) }}% · {{ __('Last read :when', ['when' => $this->ago($row['read_at'])]) }}
                                    @else
                                        {{ __('Not started yet') }}
                                    @endif
                                </p>
                            </div>

                            {{-- On a phone the red button runs the full width under its row at the standard 44px height; the others are left to the story page there. --}}
                            @if ($loop->first)
                                <x-cta :href="$row['href']" data-continue :aria-label="__('Continue reading :title', ['title' => $media->title])" class="w-full sm:w-auto">{{ __('Continue reading') }}</x-cta>
                            @else
                                <x-cta variant="secondary" :href="$row['href']" data-continue :aria-label="__('Continue :title', ['title' => $media->title])" class="hidden! sm:inline-flex!">{{ __('Continue') }}</x-cta>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section aria-labelledby="finished-title" data-section="finished" class="{{ $card }}">
            <h2 id="finished-title" class="px-5 pt-5 text-lead text-ink sm:px-6">{{ __('Finished') }}</h2>

            @if ($finishedNow + $finishedBefore === 0)
                <p class="px-5 pt-3 pb-6 text-body text-ink-subtle sm:px-6">{{ __('Stories you finish show up here, by year.') }}</p>
            @else
                <div class="divide-y divide-hairline px-5 sm:px-6">
                    @foreach ($library['years'] as $year => $figures)
                        @continue($figures['count'] === 0)
                        @php
                            $covers = $this->finished[$year] ?? collect();
                            $span = $figures['from']->isSameMonth($figures['to']) ? $figures['from']->isoFormat('MMM') : $figures['from']->isoFormat('MMM').' – '.$figures['to']->isoFormat('MMM');
                        @endphp
                        <div wire:key="year-{{ $year }}" data-year="{{ $year }}" class="flex flex-col gap-3 py-5 sm:flex-row sm:items-center sm:gap-6">
                            <div class="w-24 shrink-0">
                                <h3 class="text-lead tabular-nums text-ink">{{ $year }}</h3>
                                <p class="text-meta text-ink-subtle">{{ trans_choice(':count story|:count stories', $figures['count']) }}</p>
                                <p class="text-legal text-ink-muted">{{ $span }}</p>
                            </div>

                            <ul class="relative -mx-5 flex gap-2 overflow-x-auto px-5 sm:mx-0 sm:px-0">
                                @foreach ($covers as $entry)
                                    <li wire:key="finished-{{ $entry->id }}" data-cover class="shrink-0">
                                        <a href="{{ route('stories.show', $entry->media->slug) }}" wire:navigate class="focus-ring block aspect-[2/3] w-14 overflow-hidden rounded-control bg-surface-card">
                                            @if ($entry->media->cover_url)
                                                <img src="{{ $entry->media->cover_url }}" alt="{{ $entry->media->title }}" width="56" height="84" loading="lazy" decoding="async" class="size-full object-cover">
                                            @else
                                                <x-cover-fallback :title="$entry->media->title" compact />
                                                <span class="sr-only">{{ $entry->media->title }}</span>
                                            @endif
                                        </a>
                                    </li>
                                @endforeach
                                @if ($figures['count'] > $covers->count())
                                    <li class="flex aspect-[2/3] w-14 shrink-0 items-center justify-center rounded-control border border-hairline text-meta tabular-nums text-ink-subtle">
                                        <span aria-hidden="true">+{{ $figures['count'] - $covers->count() }}</span>
                                        <span class="sr-only">{{ __('and :count more', ['count' => $figures['count'] - $covers->count()]) }}</span>
                                    </li>
                                @endif
                            </ul>
                        </div>
                    @endforeach
                </div>
            @endif

            <a href="{{ route('library.index', ['status' => App\Enums\ReadingStatus::Completed->value]) }}" wire:navigate class="{{ $footer }}">{{ __('Show everything you’ve finished') }}<flux:icon name="arrow-right" variant="micro" class="size-4" /></a>
        </section>

        <section aria-labelledby="reviews-title" data-section="reviews" class="{{ $card }}">
            <header class="px-5 pt-5 sm:px-6">
                <h2 id="reviews-title" class="text-lead text-ink">{{ __('Your reviews') }}</h2>
                <p class="mt-1 flex items-center gap-1.5 text-legal text-ink-muted"><flux:icon name="lock-closed" variant="micro" class="size-3.5 shrink-0" />{{ __('Your name and headline show on story pages next to them') }}</p>
            </header>

            @if ($reviews['total'] === 0)
                <p class="px-5 pt-3 pb-6 text-body text-ink-subtle sm:px-6">{{ __('Reviews you write on a story’s page show up here.') }}</p>
            @else
                <ul class="divide-y divide-hairline px-5 sm:px-6">
                    @foreach ($reviews['list'] as $review)
                        @php $media = $review->media; @endphp
                        <li wire:key="review-{{ $review->id }}" class="flex gap-4 py-5">
                            <a href="{{ route('stories.show', $media->slug) }}" wire:navigate aria-hidden="true" tabindex="-1" class="block aspect-[2/3] w-14 shrink-0 self-start overflow-hidden rounded-control bg-surface-card">
                                @if ($media->cover_url)
                                    <img src="{{ $media->cover_url }}" alt="" width="56" height="84" loading="lazy" decoding="async" class="size-full object-cover">
                                @else
                                    <x-cover-fallback :title="$media->title" compact />
                                @endif
                            </a>

                            <div class="min-w-0 flex-1">
                                <h3><a href="{{ route('stories.show', ['slug' => $media->slug, 'tab' => 'reviews']) }}" wire:navigate class="focus-ring rounded-control text-body font-medium text-ink hover:underline">{{ $media->title }}</a></h3>
                                <p class="text-legal text-ink-muted">{{ collect([$this->creatorsLine($media), $this->ago($review->created_at)])->filter()->implode(' · ') }}</p>

                                @if ($review->status === App\Enums\ReviewState::Hidden)
                                    <p class="mt-2 inline-flex items-center gap-1.5 text-meta text-ink-subtle"><flux:icon name="eye-slash" variant="micro" class="size-4" />{{ __('A moderator hid this review. Only you can see it.') }}</p>
                                @endif

                                @if ($review->has_spoilers)
                                    <p class="mt-2 text-meta text-ink-subtle">{{ __('Marked as spoilers. Open the story to read it.') }}</p>
                                @else
                                    <div class="review-body mt-2 line-clamp-3 text-body text-ink">{{ App\Support\Markdown::safe($review->body) }}</div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if ($reviews['total'] > $reviews['list']->count() && ! $allReviews)
                    <button type="button" wire:click="showAllReviews" class="{{ $footer }} cursor-pointer">{{ __('Show all :count reviews', ['count' => $reviews['total']]) }}<flux:icon name="arrow-down" variant="micro" class="size-4" /></button>
                @elseif ($allReviews && $reviews['total'] > $reviews['list']->count())
                    <p class="border-t border-hairline px-5 py-3 text-center text-legal text-ink-muted">{{ __('Showing your latest :count reviews.', ['count' => $reviews['list']->count()]) }}</p>
                @endif
            @endif
        </section>

        <section aria-labelledby="collections-title" class="{{ $card }}">
            <h2 id="collections-title" class="px-5 pt-5 text-lead text-ink sm:px-6">{{ __('Collections') }}</h2>

            @if ($collections['total'] === 0)
                <p class="px-5 pt-3 pb-6 text-body text-ink-subtle sm:px-6">{{ __('No collections yet. Start one to keep a reading list, a mood or a theme.') }}</p>
            @else
                <ul class="divide-y divide-hairline px-5 sm:px-6">
                    @foreach ($collections['list'] as $collection)
                        <li wire:key="collection-{{ $collection->id }}">
                            <x-collection-row :collection="$collection" :preview="$collections['previews'][$collection->id]" />
                        </li>
                    @endforeach
                </ul>
            @endif

            <a href="{{ route('collections.index') }}" wire:navigate class="{{ $footer }}">
                {{ $collections['total'] > count($collections['list']) ? __('Show all :count collections', ['count' => $collections['total']]) : __('Open your collections') }}
                <flux:icon name="arrow-right" variant="micro" class="size-4" />
            </a>
        </section>
    </div>

    <aside aria-label="{{ __('About your profile') }}" class="mt-4 flex flex-col gap-4 lg:mt-0">
        <div class="hidden lg:block">@include('pages.profile.strength', ['where' => 'rail'])</div>

        <section aria-labelledby="library-title" class="{{ $card }} p-5">
            <h2 id="library-title" class="text-lead text-ink">{{ __('Your library') }}</h2>
            <ul class="mt-2">
                @foreach (App\Enums\ReadingStatus::cases() as $status)
                    <li wire:key="status-{{ $status->value }}">
                        <a href="{{ route('library.index', ['status' => $status->value]) }}" wire:navigate class="focus-ring -mx-2 flex min-h-11 items-center justify-between rounded-control px-2 text-meta text-ink-subtle hover:bg-surface-card hover:text-ink">
                            <span>{{ $status->label() }}</span>
                            <span class="font-medium tabular-nums text-ink">{{ number_format($library['statuses'][$status->value]) }}</span>
                        </a>
                    </li>
                @endforeach
                <li>
                    <a href="{{ route('library.index', ['favorites' => 1]) }}" wire:navigate class="focus-ring -mx-2 flex min-h-11 items-center justify-between rounded-control px-2 text-meta text-ink-subtle hover:bg-surface-card hover:text-ink">
                        <span>{{ __('Favourites') }}</span>
                        <span class="font-medium tabular-nums text-ink">{{ number_format($library['favorites']) }}</span>
                    </a>
                </li>
            </ul>
            <a href="{{ route('library.index') }}" wire:navigate class="focus-ring mt-2 inline-flex min-h-11 items-center gap-2 rounded-control text-meta text-link hover:underline">{{ __('Open your library') }}<flux:icon name="arrow-right" variant="micro" class="size-4" /></a>
        </section>

        <section aria-labelledby="private-title" class="{{ $card }} p-5">
            <h2 id="private-title" class="flex items-center gap-2 text-lead text-ink"><flux:icon name="lock-closed" class="size-5 text-ink-subtle" />{{ __('Private by design') }}</h2>
            <p class="mt-2 text-meta text-ink-subtle">{{ __('Bookflix has no public profiles. Your library, collections and reading stay yours. Reviews you post show your name and headline on that story’s page.') }}</p>
            <a href="{{ route('privacy.edit') }}" wire:navigate class="focus-ring mt-1 inline-flex min-h-11 items-center gap-2 rounded-control text-meta text-link hover:underline">{{ __('Privacy settings') }}<flux:icon name="arrow-right" variant="micro" class="size-4" /></a>
        </section>
    </aside>
</div>
</div>
