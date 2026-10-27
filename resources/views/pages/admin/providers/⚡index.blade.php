<?php

use App\Jobs\SyncProvider;
use App\Models\ExternalSource;
use App\Models\IngestionRun;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Providers')] class extends Component {
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
        return ExternalSource::providers()->orderBy('name')->get();
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

        if ($source === null || config("bookflix.providers.{$providerKey}") === null) {
            Flux::toast(variant: 'danger', text: __('That provider is not enabled.'));

            return;
        }

        SyncProvider::dispatch($source->key);

        Flux::toast(variant: 'success', text: __('Sync queued for :name.', ['name' => $source->name]));
    }
}; ?>

<section class="py-12">
    <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('admin.index')" wire:navigate>{{ __('Admin') }}</flux:button>
    <x-page-header class="mt-2" :title="__('Providers')" :description="__('Metadata sources that fill the catalog. Enable a source, then sync it now to pull its latest records.')" />

    <flux:table class="mt-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Source') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Last synced') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->sources as $source)
                <flux:table.row wire:key="source-{{ $source->id }}">
                    <flux:table.cell variant="strong">{{ $source->name }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$source->enabled ? 'green' : 'zinc'">{{ $source->enabled ? __('Enabled') : __('Disabled') }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>{{ $source->last_synced_at?->diffForHumans() ?? __('Never') }}</flux:table.cell>
                    <flux:table.cell class="flex justify-end gap-2">
                        <flux:button size="sm" variant="filled" :icon="$source->enabled ? 'pause' : 'play'" icon:variant="outline" wire:click="toggleEnabled({{ $source->id }})">
                            {{ $source->enabled ? __('Disable') : __('Enable') }}
                        </flux:button>
                        <flux:button size="sm" variant="filled" icon="arrow-path" icon:variant="outline" wire:click="syncNow('{{ $source->key }}')" :disabled="! $source->enabled" data-test="sync-now-{{ $source->key }}">
                            {{ __('Sync now') }}
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <h2 class="mt-12 text-lead text-ink">{{ __('Recent runs') }}</h2>

    <flux:table class="mt-4">
        <flux:table.columns>
            <flux:table.column>{{ __('Source') }}</flux:table.column>
            <flux:table.column>{{ __('Mode') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Started') }}</flux:table.column>
            <flux:table.column>{{ __('Fetched') }}</flux:table.column>
            <flux:table.column>{{ __('Created') }}</flux:table.column>
            <flux:table.column>{{ __('Updated') }}</flux:table.column>
            <flux:table.column>{{ __('Merged') }}</flux:table.column>
            <flux:table.column>{{ __('Skipped') }}</flux:table.column>
            <flux:table.column>{{ __('Failed') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->runs as $run)
                <flux:table.row wire:key="run-{{ $run->id }}">
                    <flux:table.cell variant="strong">{{ $run->externalSource->name }}</flux:table.cell>
                    <flux:table.cell>{{ $run->mode->value }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="match ($run->status->value) { 'succeeded' => 'green', 'partial' => 'amber', 'failed' => 'red', default => 'zinc' }">{{ $run->status->value }}</flux:badge>
                        @if ($run->error)
                            <span class="ms-2 text-meta text-ink-muted">{{ $run->error }}</span>
                        @endif
                        @if (! empty($run->counts['failed_ids']))
                            <details class="mt-1 text-meta text-ink-muted" data-test="failed-ids-{{ $run->id }}">
                                <summary>{{ __('Failed ids (:count)', ['count' => count($run->counts['failed_ids'])]) }}</summary>
                                <ul class="mt-1 space-y-0.5">
                                    @foreach ($run->counts['failed_ids'] as $failed)
                                        <li>{{ $failed['id'] }}: {{ $failed['error'] }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $run->started_at->diffForHumans() }}</flux:table.cell>
                    @foreach (['fetched', 'created', 'updated', 'merged', 'skipped', 'failed'] as $count)
                        <flux:table.cell>{{ $run->counts[$count] ?? 0 }}</flux:table.cell>
                    @endforeach
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="10" class="text-ink-muted">{{ __('No runs yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
