<?php

use App\Enums\ModelStatus;
use App\Models\ModelVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * How each version of one trained model scored when it was evaluated, over time: a line chart of one metric (Chart.js, loaded on this page only)
 * and a table of every version with all its metrics and whether it was promoted. The table always renders and is the accessible alternative;
 * the chart needs two versions. Retired versions are included. Reads `model_versions` only: no ML call.
 */
new #[Title('Compare model versions')] class extends Component {
    /** The trained models, by `model_versions.name`, with the name an admin knows them by (as on the Models page). */
    public const MODELS = [
        'item-cf' => 'Readers also read',
        'lsa' => 'Text similarity',
        'embedding' => 'Semantic similarity',
        'als' => 'Similar readers',
        'ltr' => 'Learned ranking',
        'dewey-classifier' => 'Dewey classifier',
    ];

    /** How many versions the page reads, newest first: about a year of weekly runs, a couple of months of nightly ones. */
    public const LIMIT = 60;

    #[Locked]
    public string $name = 'item-cf';

    public function boot(): void
    {
        Gate::authorize('access-admin');
    }

    public function mount(string $name = 'item-cf'): void
    {
        abort_unless(array_key_exists($name, self::MODELS), 404);

        $this->name = $name;
    }

    /**
     * The metrics this model's evaluation records, with a label and the decimals they are read to.
     *
     * @return array<string, array{label: string, decimals: int}>
     */
    #[Computed]
    public function metrics(): array
    {
        return match ($this->name) {
            'dewey-classifier' => [
                'accuracy' => ['label' => __('Accuracy'), 'decimals' => 3],
                'baseline' => ['label' => __('Baseline'), 'decimals' => 3],
            ],
            'ltr' => [
                'ndcg@10' => ['label' => __('NDCG@10'), 'decimals' => 3],
                'hybrid_ndcg@10' => ['label' => __('Hybrid NDCG@10'), 'decimals' => 3],
                'ndcg_delta' => ['label' => __('Change'), 'decimals' => 3],
            ],
            default => [
                'ndcg@10' => ['label' => __('NDCG@10'), 'decimals' => 3],
                'coverage' => ['label' => __('Coverage'), 'decimals' => 2],
                'genre_diversity' => ['label' => __('Genre diversity'), 'decimals' => 2],
                'novelty' => ['label' => __('Novelty'), 'decimals' => 1],
            ],
        };
    }

    /**
     * The newest versions first, retired ones included, ordered by when they were trained (or created, for one that never finished).
     *
     * @return Collection<int, ModelVersion>
     */
    #[Computed]
    public function versions(): Collection
    {
        return ModelVersion::query()
            ->where('name', $this->name)
            ->orderByRaw('coalesce(trained_at, created_at) desc')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'version', 'status', 'trained_at', 'created_at', 'metrics']);
    }

    /**
     * What the chart draws, oldest first: a date label per version, each metric's values (null where the version has none, which the chart
     * leaves as a gap) and whether each was promoted.
     *
     * @return array{labels: list<string>, values: array<string, list<float|null>>, promoted: list<bool>}
     */
    #[Computed]
    public function series(): array
    {
        $versions = $this->versions->reverse()->values();

        return [
            'labels' => $versions->map(fn (ModelVersion $version): string => ($version->trained_at ?? $version->created_at)?->format('M j') ?? '')->all(),
            'values' => collect($this->metrics)->map(fn (array $metric, string $key): array => $versions->map(fn (ModelVersion $version): ?float => $this->value($version, $key))->all())->all(),
            'promoted' => $versions->map(fn (ModelVersion $version): bool => ($version->metrics['promoted'] ?? false) === true)->all(),
        ];
    }

    public function value(ModelVersion $version, string $key): ?float
    {
        $value = $version->metrics[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    public function formatted(ModelVersion $version, string $key): string
    {
        $value = $this->value($version, $key);

        return $value === null ? '—' : number_format($value, $this->metrics[$key]['decimals']);
    }

    /**
     * The version's state in words. A version retired before it was ever trained is one whose training failed.
     */
    public function statusLabel(ModelVersion $version): string
    {
        if ($version->status === ModelStatus::Retired && $version->trained_at === null) {
            return __('Failed');
        }

        return __(ucfirst($version->status->value));
    }

    /**
     * Whether the evaluation promoted it, and why not when it did not, from what the evaluation recorded.
     */
    public function outcome(ModelVersion $version): string
    {
        $metrics = $version->metrics;
        $reason = is_string($metrics['reason'] ?? null) ? $metrics['reason'] : null;

        return match (true) {
            ($metrics['promoted'] ?? null) === true => __('Promoted'),
            ($metrics['promoted'] ?? null) === false => $reason ? __('Not promoted: :reason', ['reason' => $reason]) : __('Not promoted'),
            $version->status === ModelStatus::Training => __('Training'),
            $this->statusLabel($version) === __('Failed') => __('Training failed, no metrics'),
            default => __('Not evaluated yet'),
        };
    }
}; ?>

<section class="py-8">
    <x-admin-header :back="route('admin.models')" :back-label="__('Models')" :title="__('Compare model versions')" :description="__('How each trained version scored when it was evaluated. A new version replaces the active one only when it holds up against it.')" />

    <nav aria-label="{{ __('Model') }}" class="mt-6 flex flex-wrap gap-2">
        @foreach ($this::MODELS as $key => $label)
            <x-chip wire:key="model-{{ $key }}" :href="route('admin.models.compare', $key)" wire:navigate :active="$name === $key" class="min-h-11 px-4">{{ __($label) }}</x-chip>
        @endforeach
    </nav>

    @if ($this->versions->isEmpty())
        <x-admin-empty class="mt-6 rounded-card bg-surface-card" icon="chart-bar" :title="__('No versions yet')" :description="__('Run :model from Models, or wait for its schedule. Each run that trains adds a version here.', ['model' => __($this::MODELS[$name])])" />
    @else
        @php($metrics = $this->metrics)
        @php($first = array_key_first($metrics))

        @if ($this->versions->count() >= 2)
            <div
                wire:key="chart-{{ $name }}"
                wire:ignore
                x-data="versionChart({ series: @js($this->series), metrics: @js($metrics), initial: @js($first) })"
                class="mt-6 rounded-card bg-surface-card p-6"
                data-test="version-chart"
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lead text-ink"><span x-text="label">{{ $metrics[$first]['label'] }}</span> {{ __('by version') }}</h2>
                        <p class="mt-1 text-meta text-ink-subtle">{{ __($this::MODELS[$name]) }} · {{ trans_choice(':count version|:count versions', $this->versions->count(), ['count' => $this->versions->count()]) }}</p>
                    </div>
                    <div role="group" aria-label="{{ __('Metric') }}" class="flex flex-wrap gap-2">
                        @foreach ($metrics as $key => $metric)
                            <button
                                type="button"
                                x-on:click="metric = @js($key)"
                                x-bind:aria-pressed="(metric === @js($key)).toString()"
                                aria-pressed="{{ $key === $first ? 'true' : 'false' }}"
                                x-bind:class="metric === @js($key) ? 'bg-ink text-canvas' : 'bg-surface-input text-ink hover:bg-hairline'"
                                class="focus-ring inline-flex min-h-11 cursor-pointer items-center rounded-full px-4 text-meta {{ $key === $first ? 'bg-ink text-canvas' : 'bg-surface-input text-ink' }}"
                            >{{ $metric['label'] }}</button>
                        @endforeach
                    </div>
                </div>

                <div class="relative mt-6 h-64" x-show="plottable">
                    <canvas x-ref="canvas" role="img" x-bind:aria-label="label + @js(' '.__('by version, oldest to newest. The table below lists every value.'))"></canvas>
                </div>
                <p x-show="! plottable" style="display: none" class="mt-6 text-meta text-ink-subtle">{{ __('Fewer than two versions have this metric, so there is no line to draw. The table below lists what there is.') }}</p>

                <div class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2 text-meta text-ink-subtle" aria-hidden="true">
                    <span class="inline-flex items-center gap-2"><span class="size-3 rounded-full bg-accent-electric"></span>{{ __('Promoted') }}</span>
                    <span class="inline-flex items-center gap-2"><span class="size-3 rounded-full border-2 border-accent-electric"></span>{{ __('Not promoted') }}</span>
                    <span>{{ __('A gap means the run produced no metrics.') }}</span>
                </div>
            </div>
        @endif

        {{-- A table from md up; below it each version is a card, each cell named by its data-label (the header row is hidden there). --}}
        <flux:table class="mt-6 table-auto! max-md:block max-md:whitespace-normal! max-md:[&_tbody]:block max-md:[&_thead]:hidden max-md:[&_tr]:block max-md:[&_td]:block max-md:[&_td]:border-0 max-md:[&_td]:px-0">
            <flux:table.columns>
                <flux:table.column>{{ __('Version') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                @foreach ($metrics as $metric)
                    <flux:table.column>{{ $metric['label'] }}</flux:table.column>
                @endforeach
                <flux:table.column>{{ __('Outcome') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->versions as $version)
                    @php($cell = 'max-md:flex! max-md:gap-4 max-md:py-1 max-md:before:w-32 max-md:before:shrink-0 max-md:before:text-ink-subtle max-md:before:content-[attr(data-label)]')
                    @php($status = $this->statusLabel($version))
                    <flux:table.row wire:key="version-{{ $version->id }}" class="max-md:mb-4 max-md:rounded-card max-md:bg-surface-card max-md:p-4">
                        <flux:table.cell class="text-ink tabular-nums">
                            {{ ($version->trained_at ?? $version->created_at)?->format('Y-m-d H:i') }}
                            <span class="block text-legal text-ink-subtle">{{ $version->version }}</span>
                        </flux:table.cell>
                        <flux:table.cell data-label="{{ __('Status') }}" class="{{ $cell }}">
                            <span>
                                <flux:badge size="sm" :color="match ($status) { __('Active') => 'green', __('Failed') => 'red', __('Candidate'), __('Training') => 'amber', default => 'zinc' }" :icon="match ($status) { __('Active') => 'check-circle', __('Failed') => 'x-circle', __('Candidate'), __('Training') => 'clock', default => 'archive-box' }">{{ $status }}</flux:badge>
                            </span>
                        </flux:table.cell>
                        @foreach ($metrics as $key => $metric)
                            <flux:table.cell data-label="{{ $metric['label'] }}" class="{{ $cell }} tabular-nums">{{ $this->formatted($version, $key) }}</flux:table.cell>
                        @endforeach
                        <flux:table.cell data-label="{{ __('Outcome') }}" class="{{ $cell }} md:max-w-80 md:whitespace-normal!">{{ $this->outcome($version) }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <p class="mt-4 text-meta text-ink-subtle">{{ trans_choice(':count version, newest first.|:count versions, newest first.', $this->versions->count(), ['count' => $this->versions->count()]) }} {{ __('Metrics come from each version\'s own evaluation run.') }}</p>
    @endif
</section>
