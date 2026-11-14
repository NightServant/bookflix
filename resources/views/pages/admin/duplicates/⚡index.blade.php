<?php

use App\Catalog\Ingestion\Deduplicator;
use App\Enums\DuplicateStatus;
use App\Models\DuplicateCandidate;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Duplicates')] class extends Component {
    use WithPagination;

    /** The pair open in the Compare dialog. */
    public ?int $comparing = null;

    public function boot(): void
    {
        Gate::authorize('access-admin');
    }

    /**
     * @return LengthAwarePaginator<int, DuplicateCandidate>
     */
    #[Computed]
    public function candidates(): LengthAwarePaginator
    {
        return $this->pending()
            ->with(['media.mediaType', 'media.creators', 'media.externalIds.externalSource', 'duplicateMedia.mediaType', 'duplicateMedia.creators', 'duplicateMedia.externalIds.externalSource'])
            ->orderByDesc('score')
            ->orderBy('id')
            ->paginate(10);
    }

    /**
     * The pair in the Compare dialog, with what its two cards show.
     */
    #[Computed]
    public function compared(): ?DuplicateCandidate
    {
        return $this->comparing === null ? null : $this->pending()
            ->with(['media.mediaType', 'media.creators', 'media.externalIds.externalSource', 'duplicateMedia.mediaType', 'duplicateMedia.creators', 'duplicateMedia.externalIds.externalSource'])
            ->find($this->comparing);
    }

    /**
     * Open the full details of a pair side by side.
     */
    public function compare(int $candidateId): void
    {
        Gate::authorize('access-admin');

        $this->comparing = $this->pending()->findOrFail($candidateId)->id;

        Flux::modal('compare')->show();
    }

    /**
     * Fold one side of a pair into the other. The winner defaults to the pair's first media.
     */
    public function merge(int $candidateId, ?int $winnerId = null): void
    {
        Gate::authorize('access-admin');

        $candidate = $this->pending()->with(['media', 'duplicateMedia'])->findOrFail($candidateId);
        $winnerId ??= $candidate->media_id;

        abort_unless(in_array($winnerId, [$candidate->media_id, $candidate->duplicate_media_id], true), 422);

        [$winner, $loser] = $winnerId === $candidate->media_id
            ? [$candidate->media, $candidate->duplicateMedia]
            : [$candidate->duplicateMedia, $candidate->media];

        app(Deduplicator::class)->merge($winner, $loser, auth()->id());

        $this->closeCompare($candidate->id);

        Flux::toast(variant: 'success', text: __('Merged “:loser” into “:winner”.', ['loser' => $loser->title, 'winner' => $winner->title]));
    }

    /**
     * Keep both records; the pair is not proposed again.
     */
    public function dismiss(int $candidateId): void
    {
        Gate::authorize('access-admin');

        $this->pending()->findOrFail($candidateId)->update(['status' => DuplicateStatus::Dismissed, 'resolved_by' => auth()->id()]);

        $this->closeCompare($candidateId);
    }

    /**
     * Close the Compare dialog once the pair it shows has been settled.
     */
    private function closeCompare(int $candidateId): void
    {
        if ($this->comparing === $candidateId) {
            $this->comparing = null;
            unset($this->compared);

            Flux::modal('compare')->close();
        }
    }

    /**
     * Pending pairs whose two records are both still in the catalogue.
     *
     * @return Builder<DuplicateCandidate>
     */
    private function pending(): Builder
    {
        return DuplicateCandidate::query()
            ->where('status', DuplicateStatus::Pending)
            ->whereHas('media')
            ->whereHas('duplicateMedia');
    }
}; ?>

<section class="py-8">
    <x-admin-header :title="__('Duplicate review')" :description="__('Pairs the sync found similar but not certain. Keep one record to merge the other into it, or mark the pair as not a duplicate.')" />

    @if ($this->candidates->isEmpty())
        <x-admin-empty class="mt-6 rounded-card bg-surface-card" icon="square-2-stack" :title="__('No pending duplicates')" :description="__('Every similar pair has been reviewed. New ones appear here after the next sync.')" />
    @else
        {{-- A table from md up; below it each pair is a stacked list item (the table's parts become blocks). --}}
        <flux:table class="mt-6 table-auto! max-md:block max-md:whitespace-normal! max-md:[&_tbody]:block max-md:[&_thead]:hidden max-md:[&_tr]:block max-md:[&_td]:block max-md:[&_td]:border-0 max-md:[&_td]:px-0">
            <flux:table.columns>
                <flux:table.column>{{ __('Similarity') }}</flux:table.column>
                <flux:table.column>{{ __('First record') }}</flux:table.column>
                <flux:table.column>{{ __('Second record') }}</flux:table.column>
                <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->candidates as $candidate)
                    {{-- Below md the header row is hidden, so each cell carries its column's name (data-label), shown before it. --}}
                    @php($cell = 'max-md:flex! max-md:gap-4 max-md:py-1 max-md:before:w-24 max-md:before:shrink-0 max-md:before:text-ink-subtle max-md:before:content-[attr(data-label)]')
                    <flux:table.row wire:key="candidate-{{ $candidate->id }}" class="max-md:border-t max-md:border-hairline max-md:py-3">
                        <flux:table.cell data-label="{{ __('Similarity') }}" class="{{ $cell }}">
                            <flux:badge size="sm" :color="$candidate->score >= 0.95 ? 'amber' : 'zinc'">{{ __(':score% similar', ['score' => round($candidate->score * 100)]) }}</flux:badge>
                        </flux:table.cell>

                        @foreach ([$candidate->media, $candidate->duplicateMedia] as $side)
                            <flux:table.cell wire:key="candidate-{{ $candidate->id }}-{{ $side->id }}" data-label="{{ $loop->first ? __('First record') : __('Second record') }}" class="{{ $cell }} md:max-w-56 md:whitespace-normal!">
                                <span class="block min-w-0">
                                <span class="block text-body text-ink">{{ $side->title }}</span>
                                <span class="block text-meta text-ink-subtle">
                                    {{ $side->mediaType->name }}@if ($side->publication_year) · {{ $side->publication_year }}@endif
                                    @if ($side->creators->isNotEmpty()) · {{ $side->creators->pluck('name')->join(', ') }}@endif
                                    @if ($side->externalIds->isNotEmpty()) · {{ $side->externalIds->map(fn ($id) => $id->externalSource->name)->unique()->join(', ') }}@endif
                                </span>
                                @if ($side->alternative_titles)
                                    <span class="block truncate text-meta text-ink-subtle">{{ __('Also known as :titles', ['titles' => collect($side->alternative_titles)->take(4)->join(' · ')]) }}</span>
                                @endif
                                </span>
                            </flux:table.cell>
                        @endforeach

                        <flux:table.cell>
                            <div class="flex flex-wrap gap-2 md:justify-end">
                                <flux:button size="sm" variant="filled" class="min-h-11" wire:click="merge({{ $candidate->id }}, {{ $candidate->media_id }})" wire:confirm="{{ __('Keep this record and merge the other into it?') }}" :aria-label="__('Keep the first record, :title', ['title' => $candidate->media->title])">{{ __('Keep first') }}</flux:button>
                                <flux:button size="sm" variant="filled" class="min-h-11" wire:click="merge({{ $candidate->id }}, {{ $candidate->duplicate_media_id }})" wire:confirm="{{ __('Keep this record and merge the other into it?') }}" :aria-label="__('Keep the second record, :title', ['title' => $candidate->duplicateMedia->title])">{{ __('Keep second') }}</flux:button>
                                <flux:button size="sm" variant="filled" class="min-h-11" icon="x-mark" icon:variant="outline" wire:click="dismiss({{ $candidate->id }})" :aria-label="__(':first and :second are not duplicates', ['first' => $candidate->media->title, 'second' => $candidate->duplicateMedia->title])">{{ __('Not a duplicate') }}</flux:button>
                                <flux:button size="sm" variant="ghost" class="min-h-11" icon="arrows-right-left" icon:variant="outline" wire:click="compare({{ $candidate->id }})" :aria-label="__('Compare :first with :second', ['first' => $candidate->media->title, 'second' => $candidate->duplicateMedia->title])">{{ __('Compare') }}</flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-meta text-ink-subtle">{{ trans_choice(':count pair waiting|:count pairs waiting', $this->candidates->total(), ['count' => $this->candidates->total()]) }} · {{ __('most similar first') }}</p>
            <x-pager :paginator="$this->candidates" class="justify-end" />
        </div>
    @endif

    <flux:modal name="compare" variant="flyout" class="w-full max-w-4xl">
        @if ($candidate = $this->compared)
            <div class="space-y-6" role="group" aria-labelledby="compare-title">
                <div class="flex flex-wrap items-center gap-3">
                    <flux:heading size="lg" id="compare-title">{{ __('Compare records') }}</flux:heading>
                    <flux:badge size="sm" color="amber">{{ __(':score% similar', ['score' => round($candidate->score * 100)]) }}</flux:badge>
                </div>

                <div class="grid gap-6 md:grid-cols-2">
                    @foreach ([$candidate->media, $candidate->duplicateMedia] as $side)
                        <div wire:key="compare-{{ $candidate->id }}-{{ $side->id }}" class="flex flex-col rounded-card bg-surface-deep p-5">
                            <h2 class="text-lead text-ink">{{ $side->title }}</h2>
                            <p class="mt-1 text-meta text-ink-subtle">
                                {{ $side->mediaType->name }}@if ($side->publication_year) · {{ $side->publication_year }}@endif
                                @if ($side->creators->isNotEmpty()) · {{ $side->creators->pluck('name')->join(', ') }}@endif
                            </p>
                            @if ($side->externalIds->isNotEmpty())
                                <p class="mt-1 text-meta text-ink-subtle">{{ __('Source: :sources', ['sources' => $side->externalIds->map(fn ($id) => $id->externalSource->name)->unique()->join(', ')]) }}</p>
                            @endif
                            @if ($side->alternative_titles)
                                <p class="mt-1 line-clamp-2 text-meta text-ink-subtle">{{ __('Also known as :titles', ['titles' => collect($side->alternative_titles)->take(4)->join(' · ')]) }}</p>
                            @endif
                            <p class="mt-2 line-clamp-6 text-body text-ink-subtle">{{ $side->description }}</p>
                            <flux:button class="mt-4 min-h-11 self-start" size="sm" variant="filled" icon="arrows-pointing-in" icon:variant="outline" wire:click="merge({{ $candidate->id }}, {{ $side->id }})" wire:confirm="{{ __('Keep this record and merge the other into it?') }}">
                                {{ __('Keep this one') }}
                            </flux:button>
                        </div>
                    @endforeach
                </div>

                <flux:button size="sm" variant="filled" class="min-h-11" icon="x-mark" icon:variant="outline" wire:click="dismiss({{ $candidate->id }})">{{ __('Not a duplicate') }}</flux:button>
            </div>
        @endif
    </flux:modal>
</section>
