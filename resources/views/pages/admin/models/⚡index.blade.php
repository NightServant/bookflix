<?php

use App\Jobs\RunMlJob;
use App\Ml\MlClient;
use App\Models\MlJobRun;
use App\Models\ModelVersion;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Models')] class extends Component {
    /** The pipelines an admin can start: name and icon. */
    private const PIPELINES = [
        'item-cf' => ['Readers also read', 'arrow-path'],
        'lsa' => ['Text similarity', 'document-text'],
        'popularity' => ['Popularity', 'fire'],
        'embed' => ['Embeddings and summaries', 'sparkles'],
        'embedding-similarities' => ['Semantic similarity', 'light-bulb'],
        'dewey-classifier' => ['Dewey classifier', 'book-open'],
    ];

    public function boot(): void
    {
        Gate::authorize('access-admin');
    }

    /**
     * @return Collection<int, ModelVersion>
     */
    #[Computed]
    public function versions(): Collection
    {
        return ModelVersion::query()->orderByDesc('created_at')->orderByDesc('id')->limit(30)->get();
    }

    /**
     * @return Collection<int, MlJobRun>
     */
    #[Computed]
    public function runs(): Collection
    {
        return MlJobRun::query()->orderByDesc('started_at')->orderByDesc('id')->limit(20)->get();
    }

    /**
     * @return array<string, array{string, string}> pipeline key => name, icon
     */
    #[Computed]
    public function pipelines(): array
    {
        return self::PIPELINES;
    }

    /**
     * @return array<string, bool> pipeline key => whether a run of it is in progress
     */
    #[Computed]
    public function running(): array
    {
        return collect(self::PIPELINES)->map(fn (array $pipeline, string $key): bool => RunMlJob::isRunning($key))->all();
    }

    /**
     * Whether the ML service answers. This is an HTTP call made while the page renders, kept as simple as the client makes it.
     */
    #[Computed]
    public function healthy(): bool
    {
        return app(MlClient::class)->health();
    }

    /**
     * Jobs waiting on the default queue, where the pipelines run.
     */
    #[Computed]
    public function backlog(): int
    {
        return Queue::size('default');
    }

    /**
     * Queue a pipeline from its first step; the later steps follow as each one succeeds.
     */
    public function runNow(string $pipeline): void
    {
        Gate::authorize('access-admin');

        if (! array_key_exists($pipeline, RunMlJob::PIPELINES)) {
            Flux::toast(variant: 'danger', text: __('That pipeline does not exist.'));

            return;
        }

        if (! RunMlJob::dispatchPipeline($pipeline)) {
            Flux::toast(variant: 'warning', text: __(':name is already running. Wait for it to finish before starting it again.', ['name' => __(self::PIPELINES[$pipeline][0])]));

            return;
        }

        Flux::toast(variant: 'success', text: __('Queued :name.', ['name' => __(self::PIPELINES[$pipeline][0])]));
    }

    /**
     * One evaluation figure of a version (NDCG@10, coverage) to three places, or a dash when the version has none.
     *
     * @param  array<string, mixed>  $metrics
     */
    public function metric(array $metrics, string $key): string
    {
        return isset($metrics[$key]) && is_numeric($metrics[$key]) ? number_format((float) $metrics[$key], 3) : '—';
    }
}; ?>

<section class="py-8">
    <x-admin-header :title="__('Models')" :description="__('The versions the recommendations are built from, and the jobs that train them. A new version only becomes active after it passes the evaluation.')" />

    <div class="mt-6 flex flex-wrap items-center gap-x-6 gap-y-2 text-body text-ink">
        <flux:badge size="sm" :color="$this->healthy ? 'green' : 'amber'" :icon="$this->healthy ? 'check-circle' : 'exclamation-triangle'" data-test="ml-health">{{ $this->healthy ? __('ML service: healthy') : __('ML service: unavailable') }}</flux:badge>
        <span class="text-meta text-ink-subtle">{{ trans_choice(':count job waiting in the queue|:count jobs waiting in the queue', $this->backlog, ['count' => $this->backlog]) }}</span>
    </div>

    <h2 class="mt-10 text-section tracking-tight text-ink">{{ __('Run now') }}</h2>
    <p class="mt-1 text-meta text-ink-subtle">{{ __('These also run on a schedule: popularity, embeddings and summaries hourly, readers also read and semantic similarity nightly, text similarity and the Dewey classifier weekly.') }}</p>

    <div class="mt-4 flex flex-wrap gap-2">
        @foreach ($this->pipelines as $key => [$name, $icon])
            @if ($this->running[$key])
                <flux:button variant="filled" class="min-h-11" icon="arrow-path" icon:variant="outline" disabled data-test="run-now-{{ $key }}">
                    {{ __(':name: running', ['name' => __($name)]) }}
                </flux:button>
            @else
                <flux:button variant="filled" class="min-h-11" :icon="$icon" icon:variant="outline" wire:click="runNow('{{ $key }}')" data-test="run-now-{{ $key }}">
                    {{ __('Run :name', ['name' => __($name)]) }}
                </flux:button>
            @endif
        @endforeach
    </div>

    <h2 class="mt-12 text-section tracking-tight text-ink">{{ __('Model versions') }}</h2>
    <p class="mt-1 text-meta text-ink-subtle">{{ __('Newest first. Only the active version of each name serves readers.') }}</p>

    <x-admin-table :label="__('Model versions')" class="mt-4">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Version') }}</flux:table.column>
                <flux:table.column>{{ __('Kind') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Trained') }}</flux:table.column>
                <flux:table.column>{{ __('NDCG@10') }}</flux:table.column>
                <flux:table.column>{{ __('Coverage') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->versions as $version)
                    <flux:table.row wire:key="version-{{ $version->id }}">
                        <flux:table.cell variant="strong">{{ $version->name }}</flux:table.cell>
                        <flux:table.cell>{{ $version->version }}</flux:table.cell>
                        <flux:table.cell>{{ $version->kind->value }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" class="capitalize" :color="match ($version->status->value) { 'active' => 'green', 'candidate' => 'amber', default => 'zinc' }" :icon="match ($version->status->value) { 'active' => 'check-circle', 'candidate' => 'clock', default => 'minus-circle' }">{{ $version->status->value }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>{{ $version->trained_at?->diffForHumans() ?? __('Never') }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $this->metric($version->metrics, 'ndcg@10') }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $this->metric($version->metrics, 'coverage') }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7"><x-admin-empty icon="cpu-chip" :title="__('No versions yet')" :description="__('Run a pipeline above to train the first one.')" /></flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </x-admin-table>

    <h2 class="mt-12 text-section tracking-tight text-ink">{{ __('Recent job runs') }}</h2>
    <p class="mt-1 text-meta text-ink-subtle">{{ __('The last 20 steps, newest first. A failed step stops the rest of its pipeline.') }}</p>

    <x-admin-table :label="__('Recent job runs')" class="mt-4">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Job') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Started') }}</flux:table.column>
                <flux:table.column>{{ __('Finished') }}</flux:table.column>
                <flux:table.column>{{ __('Outcome') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->runs as $run)
                    <flux:table.row wire:key="run-{{ $run->id }}">
                        <flux:table.cell variant="strong">{{ $run->name }}</flux:table.cell>
                        @php($skipped = $run->error ? null : RunMlJob::skipped($run->metrics))
                        <flux:table.cell>
                            @if ($skipped)
                                <flux:badge size="sm" color="zinc" icon="minus-circle">{{ __('Skipped') }}</flux:badge>
                            @else
                                <flux:badge size="sm" class="capitalize" :color="match ($run->status) { 'succeeded' => 'green', 'failed' => 'red', 'running' => 'blue', default => 'zinc' }" :icon="match ($run->status) { 'succeeded' => 'check-circle', 'failed' => 'x-circle', 'running' => 'arrow-path', default => 'clock' }">{{ $run->status }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $run->started_at->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell>{{ $run->finished_at?->diffForHumans() ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            @php($outcome = $run->error ?: ($skipped ? __('Skipped: :reason (:have of :need)', $skipped) : '—'))
                            <span class="block max-w-sm truncate" tabindex="0" title="{{ $outcome }}">{{ $outcome }}</span>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5"><x-admin-empty icon="cpu-chip" :title="__('No runs yet')" :description="__('Choose a pipeline above, or wait for the schedule.')" /></flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </x-admin-table>
</section>
