<?php

use App\Actions\Social\DeleteReview;
use App\Actions\Social\FileReport;
use App\Actions\Social\SaveReview;
use App\Enums\ReportReason;
use App\Enums\ReviewState;
use App\Models\ExternalReview;
use App\Models\ExternalSource;
use App\Models\Media;
use App\Models\Rating;
use App\Models\Review;
use App\Models\ReviewSummary;
use App\Reviews\OutsideReviews;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The story page's Reviews tab (P10-R20): ratings, the summary of readers' reviews, excerpts from outside sources (official APIs, linked
 * back), and Bookflix readers' own reviews with the form to write one. Loaded lazily, when the tab is first shown, so the story page's
 * first paint reads nothing of it. Readers show their name and headline only; there is no profile of anyone else to link to.
 */
new class extends Component {
    private const OUTSIDE_FIRST = 3;

    private const OUTSIDE_MAX = 30;

    private const STEP = 10;

    #[Locked]
    public int $mediaId;

    public string $body = '';

    public bool $hasSpoilers = false;

    public bool $editing = false;

    public int $outsideShown = self::OUTSIDE_FIRST;

    public int $readersShown = self::STEP;

    #[Locked]
    public string $reportType = '';

    #[Locked]
    public int $reportId = 0;

    #[Locked]
    public string $reportName = '';

    public string $reason = '';

    public string $details = '';

    public function mount(int $mediaId): void
    {
        $media = Media::query()->findOrFail($mediaId);

        // The story page already turns a gated story away; this holds the line for the component on its own too.
        abort_if($media->isGatedFor(auth()->user()), 403);

        $this->mediaId = $media->id;

        OutsideReviews::refreshIfStale($media);
    }

    public function placeholder(): string
    {
        return '<div class="py-8 text-meta text-ink-subtle" role="status">'.e(__('Loading reviews…')).'</div>';
    }

    #[Computed]
    public function media(): Media
    {
        return Media::query()->findOrFail($this->mediaId);
    }

    #[Computed]
    public function canWrite(): bool
    {
        return Gate::allows('use-reader-side');
    }

    /**
     * The reader's own review, whatever its status (an array: a computed null is not cached).
     *
     * @return array{review: ?Review}
     */
    #[Computed]
    public function mine(): array
    {
        return ['review' => $this->canWrite ? Review::query()->where('user_id', auth()->id())->where('media_id', $this->mediaId)->first() : null];
    }

    /**
     * Other readers' reviews this reader may see, newest first, with their authors' names and headlines.
     *
     * @return array{total: int, list: Collection<int, Review>}
     */
    #[Computed]
    public function readers(): array
    {
        $others = fn (): Builder => Review::query()->visibleOnStory($this->media, auth()->user())->where('reviews.user_id', '!=', auth()->id());

        return [
            'total' => $others()->reorder()->count(),
            'list' => $others()->with('user.profile')->limit($this->readersShown)->get(),
        ];
    }

    /**
     * @return array{total: int, list: \Illuminate\Support\Collection<int, ExternalReview>}
     */
    #[Computed]
    public function outside(): array
    {
        $all = OutsideReviews::visibleReviews($this->media, self::OUTSIDE_MAX);

        return ['total' => $all->count(), 'list' => $all->take($this->outsideShown)];
    }

    /**
     * Bookflix readers' own average (1 to 10) and each outside source's rating on its own scale.
     *
     * @return array{average: ?float, count: int, outside: list<array{source: string, name: string, value: string, max: int, votes: ?int, url: ?string}>}
     */
    #[Computed]
    public function ratings(): array
    {
        $own = Rating::query()->where('media_id', $this->mediaId)->whereHas('user', fn (Builder $user) => $user->active())->toBase()
            ->selectRaw('avg(score) as average, count(*) as total')->first();

        $outside = OutsideReviews::ratings($this->media)
            ->filter(fn ($rating, string $source): bool => isset($this->scales()[$source]))
            ->map(fn ($rating, string $source): array => [
                'source' => $source,
                'name' => $this->sourceNames[$source] ?? $source,
                'value' => $this->onScale($rating->score, $source),
                'max' => $this->scales()[$source],
                'votes' => $rating->votes,
                'url' => $rating->url,
            ])->values()->all();

        return ['average' => $own->total > 0 ? round((float) $own->average, 1) : null, 'count' => (int) $own->total, 'outside' => $outside];
    }

    /**
     * The sources' display names, for the outside reviews and ratings shown.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function sourceNames(): array
    {
        return ExternalSource::query()->whereIn('key', array_keys($this->scales()))->pluck('name', 'key')->all();
    }

    /**
     * @return array{summary: ?ReviewSummary}
     */
    #[Computed]
    public function summary(): array
    {
        $summary = ReviewSummary::query()->where('media_id', $this->mediaId)->first();

        return ['summary' => $summary !== null && $summary->review_count >= (int) config('bookflix.social.review_summary_min_reviews', 5) ? $summary : null];
    }

    /**
     * @return array<string, int>
     */
    private function scales(): array
    {
        return config('bookflix.reviews.outside_scales', []);
    }

    /**
     * A 0 to 100 score back on the source's own scale: "72" of 100, "4.1" of 5.
     */
    public function onScale(?int $score, string $source): string
    {
        $max = $this->scales()[$source] ?? 100;
        $value = (float) $score / 100 * $max;

        return $max >= 100 ? (string) (int) round($value) : rtrim(rtrim(number_format($value, 1), '0'), '.');
    }

    public function save(): void
    {
        Gate::authorize('use-reader-side');

        $existed = $this->mine['review'] !== null;

        app(SaveReview::class)->handle(auth()->user(), $this->media, $this->body, $this->hasSpoilers);

        $this->reset('body', 'hasSpoilers', 'editing');
        unset($this->mine, $this->summary);

        Flux::toast(variant: 'success', text: $existed ? __('Review updated.') : __('Review posted.'));
    }

    public function edit(): void
    {
        $review = $this->mine['review'];

        if ($review !== null) {
            $this->body = $review->body;
            $this->hasSpoilers = $review->has_spoilers;
            $this->editing = true;
        }
    }

    public function cancelEdit(): void
    {
        $this->reset('body', 'hasSpoilers', 'editing');
        $this->resetValidation();
    }

    public function delete(): void
    {
        Gate::authorize('use-reader-side');

        $review = $this->mine['review'];

        if ($review !== null) {
            app(DeleteReview::class)->handle(auth()->user(), $review);
        }

        $this->reset('body', 'hasSpoilers', 'editing');
        unset($this->mine);

        Flux::toast(text: __('Review deleted.'));
    }

    public function showMoreOutside(): void
    {
        $this->outsideShown = min(self::OUTSIDE_MAX, $this->outsideShown + self::STEP);
    }

    public function showMoreReaders(): void
    {
        $this->readersShown += self::STEP;
    }

    /**
     * Open the report dialog for a review on this story (`review`) or an outside review (`external_review`).
     */
    public function openReport(string $type, int $id): void
    {
        Gate::authorize('use-reader-side');

        $this->resetValidation();
        $this->reset('reason', 'details');
        $this->reportType = $type;
        $this->reportId = $id;
        $this->reportName = $type === 'review'
            ? (string) Review::query()->visibleOnStory($this->media, auth()->user())->with('user')->find($id)?->user?->name
            : '';

        Flux::modal('report-review')->show();
    }

    public function sendReport(): void
    {
        Gate::authorize('use-reader-side');

        $this->validate([
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'details' => ['nullable', 'string', 'max:'.FileReport::MAX_DETAILS],
        ], ['reason.required' => __('Choose what is wrong.')]);

        $target = match ($this->reportType) {
            'review' => Review::query()->where('media_id', $this->mediaId)->find($this->reportId),
            'external_review' => ExternalReview::query()->where('media_id', $this->mediaId)->find($this->reportId),
            default => null,
        } ?? throw ValidationException::withMessages(['report' => __('You can’t report this.')]);

        app(FileReport::class)->handle(auth()->user(), $target, ReportReason::from($this->reason), $this->details);

        $this->reset('reason', 'details', 'reportType', 'reportId', 'reportName');
        Flux::modal('report-review')->close();
        Flux::toast(variant: 'success', text: __('Thanks. A moderator will look at it.'));
    }
}; ?>

@php
    $media = $this->media;
    $mine = $this->mine['review'];
    $readers = $this->readers;
    $outside = $this->outside;
    $ratings = $this->ratings;
    $summary = $this->summary['summary'];
    $names = $this->sourceNames;
    $canWrite = $this->canWrite;
    $readerTotal = $readers['total'] + ($mine !== null ? 1 : 0);
    $card = 'rounded-card bg-surface-card p-5';
    $reasons = collect(ReportReason::cases())->mapWithKeys(fn (ReportReason $reason): array => [$reason->value => $reason->label()])->all();
@endphp

{{-- `reportReturn` is the ⋯ button a report was opened from: focus goes back to it when the dialog closes. --}}
<div x-data="{ reportReturn: null }">
<div class="flex flex-col gap-10 lg:flex-row-reverse lg:items-start lg:gap-20">
    {{-- Aside: ratings and the summary. On a phone the ratings scroll sideways as small cards. --}}
    <aside aria-label="{{ __('Ratings') }}" class="flex w-full shrink-0 flex-col gap-4 lg:w-[22.5rem]">
        @if ($ratings['count'] > 0 || $ratings['outside'] !== [])
            <section aria-labelledby="ratings-title" class="lg:rounded-card lg:bg-surface-card lg:p-5">
                <h2 id="ratings-title" class="text-lead text-ink">{{ __('Ratings') }}</h2>

                <ul class="relative -mx-gutter mt-3 flex gap-3 overflow-x-auto px-gutter lg:mx-0 lg:flex-col lg:gap-0 lg:divide-y lg:divide-hairline lg:overflow-visible lg:px-0">
                    @if ($ratings['count'] > 0)
                        <li class="flex min-w-44 shrink-0 items-center justify-between gap-4 rounded-card bg-surface-card p-4 lg:min-w-0 lg:rounded-none lg:bg-transparent lg:px-0 lg:py-3">
                            <div>
                                <p class="text-meta font-medium text-ink">{{ __('Bookflix readers') }}</p>
                                <p class="text-legal text-ink-muted">{{ trans_choice(':count rating|:count ratings', $ratings['count'], ['count' => number_format($ratings['count'])]) }}</p>
                            </div>
                            <p class="text-body font-semibold tabular-nums text-ink">{{ rtrim(rtrim(number_format($ratings['average'], 1), '0'), '.') }} / 10</p>
                        </li>
                    @endif
                    @foreach ($ratings['outside'] as $rating)
                        <li wire:key="rating-{{ $rating['source'] }}" class="flex min-w-44 shrink-0 items-center justify-between gap-4 rounded-card bg-surface-card p-4 lg:min-w-0 lg:rounded-none lg:bg-transparent lg:px-0 lg:py-3">
                            <div>
                                <p class="text-meta font-medium text-ink">{{ $rating['name'] }}</p>
                                @if ($rating['votes'])
                                    <p class="text-legal text-ink-muted">{{ trans_choice(':count rating|:count ratings', $rating['votes'], ['count' => number_format($rating['votes'])]) }}</p>
                                @endif
                            </div>
                            <div class="flex items-center gap-1">
                                <p class="text-body font-semibold tabular-nums text-ink">{{ $rating['value'] }} / {{ $rating['max'] }}</p>
                                @if ($rating['url'])
                                    <a href="{{ $rating['url'] }}" target="_blank" rel="noopener noreferrer nofollow ugc" class="focus-ring -me-3 inline-flex size-11 items-center justify-center rounded-control text-ink-subtle hover:text-ink lg:-me-3">
                                        <flux:icon name="arrow-top-right-on-square" variant="micro" class="size-4" />
                                        <span class="sr-only">{{ __(':source page (opens in a new tab)', ['source' => $rating['name']]) }}</span>
                                    </a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if ($ratings['outside'] !== [])
                    <p class="mt-2 text-legal text-ink-muted">{{ __('Each site’s own scale. Updated monthly.') }}</p>
                @endif
            </section>
        @endif

        @if ($summary)
            <section aria-labelledby="summary-title" class="{{ $card }}">
                <h2 id="summary-title" class="text-lead text-ink">{{ __('What readers say') }}</h2>
                <p class="mt-3 text-body text-ink">{{ $summary->summary }}</p>
                <p class="mt-3 text-legal text-ink-muted">{{ __('Sentences picked from :count readers’ reviews.', ['count' => $summary->review_count]) }} {{ __('Reviews marked as spoilers are left out.') }}</p>
            </section>
        @endif
    </aside>

    <div class="min-w-0 flex-1 lg:max-w-[47.5rem]">
        @if ($outside['total'] > 0)
            @php $sources = $outside['list']->pluck('source')->unique()->map(fn ($key) => $names[$key] ?? $key)->values()->all(); @endphp
            <section aria-labelledby="outside-title" data-section="outside-reviews" class="border-b border-hairline pb-10">
                <h2 id="outside-title" class="text-section text-ink">{{ __('Around the web') }} <span class="text-meta font-normal text-ink-muted">{{ number_format($outside['total']) }}</span></h2>
                <p class="mt-1 text-meta text-ink-subtle">{{ __('Short excerpts from :sources, through their official APIs. The full reviews stay on their sites.', ['sources' => Illuminate\Support\Arr::join($sources, ', ', ' '.__('and').' ')]) }}</p>

                <ul class="mt-4 divide-y divide-hairline">
                    @foreach ($outside['list'] as $review)
                        @php
                            $source = $names[$review->source] ?? $review->source;
                            $max = config('bookflix.reviews.outside_scales.'.$review->source);
                        @endphp
                        <li wire:key="outside-{{ $review->id }}" class="py-5">
                            <div class="flex items-center gap-2">
                                <span class="rounded-control border border-hairline px-1.5 py-0.5 text-legal text-ink-subtle">{{ $source }}</span>
                                <p class="min-w-0 flex-1 truncate text-meta text-ink-muted">
                                    <span class="text-ink">{{ $review->author_name ?? __('A reader') }}</span>
                                    @if ($review->rating !== null && $max)
                                        · {{ __(':value out of :max', ['value' => $this->onScale($review->rating, $review->source), 'max' => $max]) }}
                                    @endif
                                    @if ($review->published_at)
                                        · <time datetime="{{ $review->published_at->toDateString() }}">{{ $review->published_at->isoFormat('MMMM YYYY') }}</time>
                                    @endif
                                </p>
                                @if ($canWrite)
                                    <x-cta variant="secondary" icon="ellipsis-horizontal" wire:click="openReport('external_review', {{ $review->id }})" x-on:click="reportReturn = $el" :aria-label="__('Report this review')" class="bg-transparent! hover:bg-surface-card!" />
                                @endif
                            </div>
                            <p class="mt-2 text-body text-ink">{{ $review->excerpt }}</p>
                            <a href="{{ $review->url }}" target="_blank" rel="noopener noreferrer nofollow ugc" class="focus-ring mt-1 inline-flex min-h-11 items-center gap-1.5 rounded-control text-meta text-link hover:underline">
                                {{ __('Read the full review on :source', ['source' => $source]) }}<span class="sr-only"> {{ __('(opens in a new tab)') }}</span>
                                <flux:icon name="arrow-top-right-on-square" variant="micro" class="size-4" />
                            </a>
                        </li>
                    @endforeach
                </ul>

                @if ($outside['total'] > $outside['list']->count())
                    <x-cta variant="secondary" wire:click="showMoreOutside">{{ __('Show :count more', ['count' => $outside['total'] - $outside['list']->count()]) }}</x-cta>
                @endif
            </section>
        @endif

        <section aria-labelledby="readers-title" data-section="reader-reviews" @class(['pt-10' => $outside['total'] > 0])>
            <h2 id="readers-title" class="text-section text-ink">{{ __('Bookflix readers') }} <span class="text-meta font-normal text-ink-muted">{{ trans_choice(':count review|:count reviews', $readerTotal, ['count' => number_format($readerTotal)]) }}</span></h2>

            @if ($canWrite)
                <div class="mt-6 border-b border-hairline pb-8">
                    <h3 class="text-lead text-ink">{{ __('Your review') }}</h3>

                    @if ($mine !== null && ! $editing)
                        @include('partials.review-card', ['review' => $mine, 'own' => true])
                    @else
                        <form wire:submit="save" class="mt-3">
                            <label for="review-body" class="sr-only">{{ __('Your review') }}</label>
                            <textarea
                                id="review-body"
                                wire:model="body"
                                x-data="{ length: 0 }"
                                x-init="length = $el.value.length"
                                x-on:input="length = $el.value.length; $refs.counter.textContent = length.toLocaleString()"
                                rows="5"
                                maxlength="{{ SaveReview::MAX_BODY }}"
                                placeholder="{{ __('What did you think? Be kind; say why.') }}"
                                aria-describedby="review-help"
                                @class(['block w-full rounded-control border bg-surface-input px-3 py-2.5 text-body text-ink placeholder:text-ink-muted focus-ring', 'border-red-400' => $errors->has('body'), 'border-border-input' => ! $errors->has('body')])
                            ></textarea>
                            <p id="review-help" class="mt-1.5 text-legal text-ink-muted">
                                {{ __('*Italics*, **bold**, lists and links work.') }}
                                <span><span x-ref="counter">{{ number_format(mb_strlen($body)) }}</span> / {{ number_format(SaveReview::MAX_BODY) }}</span>
                            </p>
                            @error('body')<p class="mt-1.5 text-meta text-red-400">{{ $message }}</p>@enderror
                            @error('review')<p class="mt-1.5 text-meta text-red-400">{{ $message }}</p>@enderror

                            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                                <label class="inline-flex min-h-11 cursor-pointer items-center gap-2.5 text-meta text-ink">
                                    <input type="checkbox" wire:model="hasSpoilers" class="filter-check">
                                    {{ __('It has spoilers') }}
                                </label>

                                <div class="flex gap-3">
                                    @if ($editing)
                                        <x-cta variant="secondary" type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-cta>
                                    @endif
                                    <x-cta variant="light" type="submit">{{ $editing ? __('Update review') : __('Post review') }}</x-cta>
                                </div>
                            </div>
                        </form>
                    @endif
                </div>
            @endif

            @if ($readers['total'] > 0)
                <p class="mt-6 text-end text-legal text-ink-muted">{{ __('Newest first') }}</p>
                <ul class="mt-2 flex flex-col gap-4">
                    @foreach ($readers['list'] as $review)
                        <li wire:key="review-{{ $review->id }}">
                            @include('partials.review-card', ['review' => $review, 'own' => false])
                        </li>
                    @endforeach
                </ul>

                @if ($readers['total'] > $readers['list']->count())
                    <x-cta variant="secondary" wire:click="showMoreReaders" class="mt-4">{{ __('Show more reviews') }}</x-cta>
                @endif
            @elseif ($mine === null)
                <p class="mt-6 text-body text-ink-subtle">{{ __('No reviews from Bookflix readers yet.') }}</p>
            @endif
        </section>
    </div>
</div>

    @if ($canWrite)
        {{-- The report dialog: a native modal <dialog> (focus moves in, Escape closes, focus goes back to the ⋯ button). --}}
        <flux:modal name="report-review" class="w-full max-w-md text-ink shadow-overlay!" x-on:close="$nextTick(() => reportReturn?.focus())">
            <form wire:submit="sendReport" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg" level="2" tabindex="-1" autofocus class="outline-none">{{ __('Report this review') }}</flux:heading>
                    <p class="mt-1 text-meta text-ink-subtle">
                        {{ __('Tell us what’s wrong. A moderator will look at it.') }}
                        @if ($reportName !== '')
                            {{ __(':name won’t know it was you.', ['name' => $reportName]) }}
                        @endif
                    </p>
                </div>

                <flux:radio.group wire:model="reason" :label="__('Reason')">
                    @foreach ($reasons as $value => $label)
                        <flux:radio :value="$value" :label="$label" />
                    @endforeach
                </flux:radio.group>

                <flux:textarea wire:model="details" :label="__('Anything we should know?')" :badge="__('Optional')" rows="3" maxlength="{{ FileReport::MAX_DETAILS }}" />

                @error('report')<p class="text-meta text-red-400">{{ $message }}</p>@enderror

                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:modal.close>
                        <x-cta variant="secondary" type="button" class="w-full">{{ __('Cancel') }}</x-cta>
                    </flux:modal.close>
                    <x-cta variant="light" type="submit" class="w-full">{{ __('Send report') }}</x-cta>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
