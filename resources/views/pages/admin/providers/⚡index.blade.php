<?php

use App\Jobs\SyncProvider;
use App\Models\ExternalSource;
use App\Enums\IngestionStatus;
use App\Models\IngestionRun;
use App\Models\MediaExternalId;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sync')] class extends Component {
    public function boot(): void
    {
        Gate::authorize('access-admin');
    }

    /**
     * @return Collection<int, ExternalSource>
     */
    #[Computed]
    public function sources(): Collection
    {
        return ExternalSource::providers()->whereIn('key', array_keys(array_filter(config('bookflix.providers'), fn (array $provider): bool => $provider['class'] !== null)))->orderBy('name')->get();
    }

    /**
     * @return Collection<int, IngestionRun>
     */
    #[Computed]
    public function runs(): Collection
    {
        return IngestionRun::with('externalSource')->latest('started_at')->limit(20)->get();
    }

    /**
     * How many catalog records each source has supplied, by source id: one grouped count over the (source, external id) index.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function records(): array
    {
        return MediaExternalId::query()->toBase()->selectRaw('external_source_id, count(*) as total')->groupBy('external_source_id')->pluck('total', 'external_source_id')->map(fn (mixed $total): int => (int) $total)->all();
    }

    /**
     * The sources a sync is running for right now.
     *
     * @return array<int, true>
     */
    #[Computed]
    public function running(): array
    {
        return IngestionRun::query()->where('status', IngestionStatus::Running)->pluck('external_source_id')->mapWithKeys(fn (int $id): array => [$id => true])->all();
    }

    /**
     * Turn a source on or off; the scheduler only syncs enabled sources.
     */
    public function toggleEnabled(int $sourceId): void
    {
        Gate::authorize('access-admin');

        $source = ExternalSource::providers()->findOrFail($sourceId);
        $source->update(['enabled' => ! $source->enabled]);

        unset($this->sources);
    }

    /**
     * Queue an incremental sync for an enabled source. A sync already queued or running for it is not doubled up.
     */
    public function syncNow(string $providerKey): void
    {
        Gate::authorize('access-admin');

        $source = ExternalSource::providers()->where('key', $providerKey)->where('enabled', true)->first();

        if ($source === null || config("bookflix.providers.{$providerKey}.class") === null) {
            Flux::toast(variant: 'danger', text: __('That provider is not enabled.'));

            return;
        }

        SyncProvider::dispatch($source->key);

        Flux::toast(variant: 'success', text: __('Sync queued for :name.', ['name' => $source->name]));
    }
}; ?>

<section class="py-8">
    <x-admin-header :title="__('Sync')" :description="__('Metadata sources that fill the catalog. Enable a source, then sync it now to pull its latest records.')" />

    <x-admin-table :label="__('Sources')" class="mt-6">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Source') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Last synced') }}</flux:table.column>
                <flux:table.column>{{ __('Records') }}</flux:table.column>
                <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->sources as $source)
                    @php($running = isset($this->running[$source->id]))
                    <flux:table.row wire:key="source-{{ $source->id }}">
                        <flux:table.cell variant="strong">{{ $source->name }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($running)
                                <flux:badge size="sm" color="blue" icon="arrow-path">{{ __('Running') }}</flux:badge>
                            @else
                                <flux:badge size="sm" :color="$source->enabled ? 'green' : 'zinc'" :icon="$source->enabled ? 'check-circle' : 'pause-circle'">{{ $source->enabled ? __('Enabled') : __('Disabled') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $running ? __('Running now') : ($source->last_synced_at?->diffForHumans() ?? __('Never')) }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ Number::format($this->records[$source->id] ?? 0) }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" variant="filled" class="min-h-11" :icon="$source->enabled ? 'pause' : 'play'" icon:variant="outline" wire:click="toggleEnabled({{ $source->id }})">
                                    {{ $source->enabled ? __('Disable') : __('Enable') }}
                                </flux:button>
                                <flux:button size="sm" variant="filled" class="min-h-11" icon="arrow-path" icon:variant="outline" wire:click="syncNow('{{ $source->key }}')" :disabled="! $source->enabled" data-test="sync-now-{{ $source->key }}">
                                    {{ __('Sync now') }}
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </x-admin-table>

    <h2 class="mt-12 text-section tracking-tight text-ink">{{ __('Recent Runs') }}</h2>
    <p class="mt-1 text-meta text-ink-subtle">{{ __('The last 20 syncs, newest first.') }}</p>

    <x-admin-table :label="__('Recent runs')" class="mt-4">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Source') }}</flux:table.column>
                <flux:table.column>{{ __('Mode') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Started') }}</flux:table.column>
                <flux:table.column>{{ __('Records') }}</flux:table.column>
                <flux:table.column>{{ __('Detail') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->runs as $run)
                    @php($detail = $run->error ?: __(':updated updated, :created new, :failed failed', ['updated' => $run->counts['updated'] ?? 0, 'created' => $run->counts['created'] ?? 0, 'failed' => $run->counts['failed'] ?? 0]))
                    <flux:table.row wire:key="run-{{ $run->id }}">
                        <flux:table.cell variant="strong">{{ $run->externalSource->name }}</flux:table.cell>
                        <flux:table.cell class="capitalize">{{ $run->mode->value }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" class="capitalize" :color="match ($run->status->value) { 'succeeded' => 'green', 'partial' => 'amber', 'failed' => 'red', 'running' => 'blue', default => 'zinc' }" :icon="match ($run->status->value) { 'succeeded' => 'check-circle', 'partial' => 'exclamation-triangle', 'failed' => 'x-circle', default => 'arrow-path' }">{{ $run->status->value }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>{{ $run->started_at->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ $run->counts['fetched'] ?? 0 }}</flux:table.cell>
                        <flux:table.cell>
                            <span class="block max-w-sm truncate" tabindex="0" title="{{ $detail }}">{{ $detail }}</span>
                            @if (! empty($run->counts['failed_ids']))
                                <details class="mt-1 text-meta text-ink-subtle" data-test="failed-ids-{{ $run->id }}">
                                    <summary class="focus-ring inline-flex min-h-11 cursor-pointer items-center">{{ __('Failed ids (:count)', ['count' => count($run->counts['failed_ids'])]) }}</summary>
                                    <ul class="mt-1 space-y-0.5">
                                        @foreach ($run->counts['failed_ids'] as $failed)
                                            <li>{{ $failed['id'] }}: {{ $failed['error'] }}</li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6"><x-admin-empty icon="arrow-path" :title="__('No runs yet')" :description="__('Enable a source and choose Sync now to start the first one.')" /></flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </x-admin-table>
</section>
