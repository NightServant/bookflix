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
            ->with(['media.mediaType', 'media.creators', 'duplicateMedia.mediaType', 'duplicateMedia.creators'])
            ->orderByDesc('score')
            ->orderBy('id')
            ->paginate(10);
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

        Flux::toast(variant: 'success', text: __('Merged “:loser” into “:winner”.', ['loser' => $loser->title, 'winner' => $winner->title]));
    }

    /**
     * Keep both records; the pair is not proposed again.
     */
    public function dismiss(int $candidateId): void
    {
        Gate::authorize('access-admin');

        $this->pending()->findOrFail($candidateId)->update(['status' => DuplicateStatus::Dismissed, 'resolved_by' => auth()->id()]);
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

<section class="py-12">
    <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('admin.index')" wire:navigate>{{ __('Admin') }}</flux:button>
    <h1 class="mt-2 text-section text-ink">{{ __('Duplicate review') }}</h1>
    <p class="mt-1 text-body text-ink-subtle">{{ __('Pairs the sync found similar but not certain. Merging moves ids and relations to the record you keep; the other record\'s URL redirects to it.') }}</p>

    <div class="mt-6 space-y-6">
        @forelse ($this->candidates as $candidate)
            <article wire:key="candidate-{{ $candidate->id }}" class="rounded-lg bg-surface-card p-6">
                <div class="flex items-center justify-between">
                    <flux:badge size="sm" color="amber">{{ __(':score% similar', ['score' => round($candidate->score * 100)]) }}</flux:badge>
                    <flux:button size="sm" variant="filled" icon="x-mark" icon:variant="outline" wire:click="dismiss({{ $candidate->id }})">{{ __('Not a duplicate') }}</flux:button>
                </div>

                <div class="mt-4 grid gap-6 md:grid-cols-2">
                    @foreach ([$candidate->media, $candidate->duplicateMedia] as $side)
                        <div wire:key="candidate-{{ $candidate->id }}-{{ $side->id }}" class="flex flex-col">
                            <h2 class="text-lead text-ink">{{ $side->title }}</h2>
                            <p class="mt-1 text-meta text-ink-muted">
                                {{ $side->mediaType->name }}@if ($side->publication_year) · {{ $side->publication_year }}@endif
                                @if ($side->creators->isNotEmpty()) · {{ $side->creators->pluck('name')->join(', ') }}@endif
                            </p>
                            <p class="mt-2 line-clamp-4 text-body text-ink-subtle">{{ $side->description }}</p>
                            <flux:button class="mt-4 self-start" size="sm" variant="filled" icon="arrows-pointing-in" icon:variant="outline" wire:click="merge({{ $candidate->id }}, {{ $side->id }})" wire:confirm="{{ __('Keep this record and merge the other into it?') }}">
                                {{ __('Keep this one') }}
                            </flux:button>
                        </div>
                    @endforeach
                </div>
            </article>
        @empty
            <p class="text-body text-ink-muted">{{ __('No pending duplicates.') }}</p>
        @endforelse
    </div>

    <div class="mt-6">
        <flux:pagination :paginator="$this->candidates" />
    </div>
</section>
