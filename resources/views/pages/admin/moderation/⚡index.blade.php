<?php

use App\Actions\Social\DismissReport;
use App\Actions\Social\HideReportedContent;
use App\Actions\Social\RestoreContent;
use App\Admin\AdminCounts;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\ReviewState;
use App\Models\ExternalReview;
use App\Models\ExternalSource;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Admin, Reports (P10-R10): what readers reported, one card per reported thing (a reader's review or an outside review). Hide takes it off every
 * page and closes every open report about it; Dismiss closes them and keeps it; Restore puts a hidden one back. Nothing is deleted. The cards are
 * one grouped query, their targets three more, so the page costs the same however many it shows.
 */
new #[Title('Reports')] class extends Component {
    use WithPagination;

    private const TYPES = ['review', 'external_review'];

    private const STATUSES = ['open', 'actioned', 'dismissed', 'all'];

    #[Url]
    public string $status = 'open';

    #[Url]
    public string $type = '';

    #[Url]
    public string $reason = '';

    public function boot(): void
    {
        Gate::authorize('access-admin');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'type', 'reason'], true)) {
            $this->resetPage();
        }
    }

    /**
     * One row per reported thing: how many reports, the oldest, the commonest reason, the first note a reader wrote, and who handled it last.
     *
     * @return LengthAwarePaginator<int, stdClass>
     */
    #[Computed]
    public function cards(): LengthAwarePaginator
    {
        $status = in_array($this->status, self::STATUSES, true) ? $this->status : 'open';
        $handled = in_array($status, ['actioned', 'dismissed'], true);

        return Report::query()->toBase()
            ->select('reportable_type', 'reportable_id')
            ->selectRaw('count(*) as total')
            ->selectRaw("count(*) filter (where status = 'open') as open_total")
            ->selectRaw('min(created_at) as oldest')
            ->selectRaw('max(handled_at) as handled_at')
            ->selectRaw('max(id) as latest_id')
            ->selectRaw("max(id) filter (where status = 'open') as open_id")
            ->selectRaw('mode() within group (order by reason) as reason')
            ->selectRaw('(array_agg(details order by created_at) filter (where details is not null))[1] as details')
            ->selectRaw('(array_agg(handled_by order by handled_at desc nulls last))[1] as handled_by')
            ->selectRaw('(array_agg(status order by handled_at desc nulls last))[1] as last_status')
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when(in_array($this->type, self::TYPES, true), fn ($query) => $query->where('reportable_type', $this->type))
            ->when(ReportReason::tryFrom($this->reason) !== null, fn ($query) => $query->where('reason', $this->reason))
            ->groupBy('reportable_type', 'reportable_id')
            ->when($handled, fn ($query) => $query->orderByDesc('handled_at'), fn ($query) => $query->orderBy('oldest'))
            ->orderBy('reportable_id')
            ->paginate(25);
    }

    /**
     * The things the page's cards are about, and the names they need, in three queries.
     *
     * @return array{reviews: \Illuminate\Support\Collection<int, Review>, outside: \Illuminate\Support\Collection<int, ExternalReview>, handlers: array<int, string>, sources: array<string, string>}
     */
    #[Computed]
    public function targets(): array
    {
        $cards = collect($this->cards->items());
        $ids = fn (string $type) => $cards->where('reportable_type', $type)->pluck('reportable_id')->all();

        return [
            'reviews' => Review::withTrashed()->with(['user:id,name', 'media:id,title,slug'])->whereIn('id', $ids('review'))->get()->keyBy('id'),
            'outside' => ExternalReview::query()->with('media:id,title,slug')->whereIn('id', $ids('external_review'))->get()->keyBy('id'),
            'handlers' => User::query()->whereIn('id', $cards->pluck('handled_by')->filter()->unique()->all())->pluck('name', 'id')->all(),
            'sources' => ExternalSource::query()->pluck('name', 'key')->all(),
        ];
    }

    /**
     * The open things waiting, for the Open chip (the same figure as the menu badge, without its minute of cache).
     */
    #[Computed]
    public function openCount(): int
    {
        return (int) Report::query()->where('status', ReportStatus::Open->value)->toBase()->selectRaw('count(distinct (reportable_type, reportable_id)) as total')->value('total');
    }

    public function hide(int $reportId): void
    {
        app(HideReportedContent::class)->handle(auth()->user(), Report::query()->findOrFail($reportId));
        $this->done(__('Hidden. Every open report about it is closed.'));
    }

    public function dismiss(int $reportId): void
    {
        app(DismissReport::class)->handle(auth()->user(), Report::query()->findOrFail($reportId));
        $this->done(__('Dismissed. The content stays as it is.'));
    }

    public function restore(int $reportId): void
    {
        app(RestoreContent::class)->handle(auth()->user(), Report::query()->findOrFail($reportId));
        $this->done(__('Restored. It shows on the story page again.'));
    }

    private function done(string $message): void
    {
        Cache::forget(AdminCounts::CACHE_KEY);
        unset($this->cards, $this->targets, $this->openCount);
        Flux::toast(variant: 'success', text: $message);
    }

    public function age(?string $at): string
    {
        return $at === null ? '' : Carbon::parse($at)->diffForHumans();
    }
}; ?>

@php
    $cards = $this->cards;
    $targets = $this->targets;
    $chip = 'focus-ring inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full px-4 text-meta';
@endphp

<section class="py-8">
    <x-admin-header :title="__('Reports')" :description="__('What readers reported, oldest first. Hiding takes the content off every page at once and closes every report about it; nothing is deleted, and Restore puts it back.')" />

    <div class="mt-6 flex flex-wrap items-center gap-2">
        <div role="group" aria-label="{{ __('Status') }}" class="flex flex-wrap gap-2">
            @foreach (['open' => __('Open'), 'actioned' => __('Actioned'), 'dismissed' => __('Dismissed'), 'all' => __('All')] as $value => $label)
                <button type="button" wire:click="$set('status', '{{ $value }}')" aria-pressed="{{ $status === $value ? 'true' : 'false' }}" @class([$chip, 'bg-ink text-canvas' => $status === $value, 'bg-surface-card text-ink hover:bg-hairline' => $status !== $value])>
                    @if ($status === $value)<flux:icon name="check" variant="micro" class="size-4" />@endif
                    {{ $label }}@if ($value === 'open' && $this->openCount > 0) <span class="tabular-nums">{{ Number::format($this->openCount) }}</span>@endif
                </button>
            @endforeach
        </div>

        <div class="grid w-full grid-cols-2 gap-2 sm:ms-2 sm:flex sm:w-auto">
            <div class="sm:w-40"><flux:select wire:model.live="type" :aria-label="__('Type')">
                <flux:select.option value="">{{ __('All types') }}</flux:select.option>
                <flux:select.option value="review">{{ __('Review') }}</flux:select.option>
                <flux:select.option value="external_review">{{ __('Outside review') }}</flux:select.option>
            </flux:select></div>
            <div class="sm:w-52"><flux:select wire:model.live="reason" :aria-label="__('Reason')">
                <flux:select.option value="">{{ __('All reasons') }}</flux:select.option>
                @foreach (ReportReason::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select></div>
        </div>
    </div>

    @if ($cards->isEmpty())
        <x-admin-empty class="mt-6 rounded-card bg-surface-card" icon="flag" :title="__('Nothing to look at')" :description="$status === 'open' ? __('No open reports. When a reader reports a review, it shows up here.') : __('No reports match these filters.')" />
    @else
        <ul class="mt-6 flex flex-col gap-3">
            @foreach ($cards as $card)
                @php
                    $isReview = $card->reportable_type === 'review';
                    $target = $isReview ? $targets['reviews']->get($card->reportable_id) : $targets['outside']->get($card->reportable_id);
                    $removed = $target === null || ($isReview && $target->trashed()) || $target->media === null;
                    $hidden = ! $removed && ($isReview ? $target->status === ReviewState::Hidden : $target->hidden_at !== null);
                    $source = ! $isReview && $target ? ($targets['sources'][$target->source] ?? $target->source) : null;
                    $reason = ReportReason::tryFrom((string) $card->reason);
                    $handler = $card->handled_by ? ($targets['handlers'][$card->handled_by] ?? __('a former admin')) : null;
                @endphp
                <li wire:key="card-{{ $card->reportable_type }}-{{ $card->reportable_id }}" data-report-card class="flex flex-col gap-4 rounded-card border border-hairline bg-surface-deep p-5 lg:flex-row lg:items-start lg:gap-6">
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2">
                            <span class="rounded-control border border-hairline px-1.5 py-0.5 text-legal text-ink-subtle">{{ $isReview ? __('Review') : __('Outside review') }}</span>
                            @if ($removed)
                                <span class="text-body text-ink-subtle">{{ __('Removed') }}</span>
                            @else
                                <a href="{{ route('stories.show', ['slug' => $target->media->slug, 'tab' => 'reviews']) }}" class="focus-ring rounded-control text-body font-medium text-ink hover:underline">{{ $target->media->title }}@if ($source) · {{ $source }}@endif</a>
                            @endif
                        </p>

                        @if ($removed)
                            <p class="mt-1 text-meta text-ink-subtle">{{ $isReview ? __('The review was deleted by its author since') : __('The outside review is no longer shown on Bookflix') }}</p>
                        @else
                            <p class="mt-1 text-meta text-ink-subtle">
                                {{ $isReview ? __('Review by :name', ['name' => $target->user?->name ?? __('a deleted account')]) : __('From :source, by :author', ['source' => $source, 'author' => $target->author_name ?? __('a reader')]) }}
                                @if (! $isReview)<span class="text-ink-muted"> · {{ __('excerpt shown on the story page') }}</span>@endif
                                @if ($hidden)<span class="text-ink-muted"> · {{ __('hidden') }}</span>@endif
                            </p>
                            <div class="review-body mt-3 rounded-control bg-surface-card px-4 py-3 text-body text-ink">
                                @if ($isReview)
                                    {{ App\Support\Markdown::safe($target->body) }}
                                @else
                                    <p>{{ $target->excerpt }}</p>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start lg:w-[26rem] lg:shrink-0">
                        <div class="min-w-0 flex-1 text-meta">
                            <p class="font-medium text-ink">{{ $reason?->label() }}</p>
                            <p class="text-ink-subtle">
                                {{ trans_choice(':count report|:count reports', (int) $card->total, ['count' => Number::format((int) $card->total)]) }}@if ($card->details) · “{{ Str::limit($card->details, 80) }}”@endif
                            </p>
                            @if ((int) $card->open_total > 0)
                                <p class="text-ink-muted">{{ __('Oldest :age', ['age' => $this->age($card->oldest)]) }}</p>
                            @elseif ($card->last_status === ReportStatus::Actioned->value)
                                <p class="text-ink-muted">{{ __('Hidden by :name', ['name' => $handler]) }} · {{ $this->age($card->handled_at) }} · {{ trans_choice('closed :count report|closed :count reports', (int) $card->total, ['count' => (int) $card->total]) }}</p>
                            @elseif ($card->last_status === ReportStatus::Dismissed->value)
                                <p class="text-ink-muted">{{ __('Dismissed by :name', ['name' => $handler]) }} · {{ $this->age($card->handled_at) }}</p>
                            @endif
                        </div>

                        <div class="grid grid-cols-2 gap-2 sm:flex sm:shrink-0">
                            @if ((int) $card->open_total > 0)
                                @unless ($removed)
                                    <x-cta variant="light" wire:click="hide({{ $card->open_id }})" class="w-full sm:w-auto">{{ __('Hide') }}</x-cta>
                                @endunless
                                <x-cta variant="secondary" wire:click="dismiss({{ $card->open_id }})" @class(['w-full sm:w-auto', 'col-span-2' => $removed])>{{ __('Dismiss') }}</x-cta>
                            @elseif ($hidden)
                                <x-cta variant="secondary" wire:click="restore({{ $card->latest_id }})" class="col-span-2 w-full sm:w-auto">{{ __('Restore') }}</x-cta>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-meta text-ink-subtle">{{ trans_choice(':count reported thing|:count reported things', $cards->total(), ['count' => Number::format($cards->total())]) }} · {{ in_array($status, ['actioned', 'dismissed'], true) ? __('latest first') : __('oldest first') }} · {{ __('25 per page') }}</p>
            <x-pager :paginator="$cards" class="justify-end" />
        </div>
    @endif
</section>
