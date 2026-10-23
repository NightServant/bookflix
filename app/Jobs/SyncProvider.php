<?php

namespace App\Jobs;

use App\Catalog\Data\ProviderRecord;
use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Enums\IngestionMode;
use App\Enums\IngestionStatus;
use App\Models\ExternalSource;
use App\Models\IngestionRun;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

class SyncProvider implements ShouldQueue
{
    use Queueable;

    /**
     * Rate-limit releases still count as attempts, so a `$tries` cap would fail a sync that only waited its turn.
     * Time bounds the waiting instead and only real exceptions are capped.
     */
    public int $maxExceptions = 5;

    public function __construct(
        public string $providerKey,
        public IngestionMode $mode = IngestionMode::Incremental,
    ) {
        $this->onQueue('ingestion');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * Fetch the changed records and queue one import per record. A failed fetch ends the run before anything is imported;
     * a provider outage is retried by the next scheduled sync, a 429 by releasing this job. Otherwise the batch finalizes the run.
     */
    public function handle(): void
    {
        $source = ExternalSource::where('key', $this->providerKey)->firstOrFail();
        $run = IngestionRun::create(['external_source_id' => $source->id, 'mode' => $this->mode, 'started_at' => now()]);

        try {
            // ponytail: the whole fetch is held in memory and batched at once, chunk the stream when --full runs get big.
            $imports = app(config("bookflix.providers.{$this->providerKey}.class"))
                ->changedSince($this->mode === IngestionMode::Full ? null : $this->since($source))
                ->map(fn (ProviderRecord $record): ImportMediaRecord => new ImportMediaRecord($record, $this->providerKey, $run->id))
                ->all();
        } catch (RateLimitedByProvider $exception) {
            $this->markFailed($run, $exception->getMessage());
            $this->release($exception->retryAfter);

            return;
        } catch (ProviderUnavailable $exception) {
            $this->markFailed($run, $exception->getMessage());

            return;
        }

        IngestionRun::whereKey($run->id)->update(['counts->fetched' => count($imports)]);

        if ($imports === []) {
            self::finalize($run->id, 0, 0);

            return;
        }

        $runId = $run->id;

        Bus::batch($imports)
            ->name("sync:{$this->providerKey}")
            ->onQueue('ingestion')
            ->allowFailures()
            ->finally(static fn (Batch $batch) => self::finalize($runId, $batch->totalJobs, $batch->failedJobs))
            ->dispatch();
    }

    /**
     * Close the run once every import has run: all fine is succeeded, some failed is partial, all failed is failed.
     * Only a succeeded run moves the sync cursor, so the next sync re-reads whatever this one missed.
     */
    public static function finalize(int $runId, int $total, int $failed): void
    {
        $run = IngestionRun::with('externalSource')->findOrFail($runId);

        $status = match (true) {
            $failed === 0 => IngestionStatus::Succeeded,
            $failed >= $total => IngestionStatus::Failed,
            default => IngestionStatus::Partial,
        };

        $run->update([
            'status' => $status,
            'finished_at' => now(),
            'error' => $failed > 0 ? "{$failed} of {$total} records failed to import." : null,
        ]);

        if ($status === IngestionStatus::Succeeded) {
            $run->externalSource->update(['sync_cursor' => ['since' => $run->started_at->toIso8601String()], 'last_synced_at' => now()]);
        }
    }

    private function markFailed(IngestionRun $run, string $error): void
    {
        $run->update(['status' => IngestionStatus::Failed, 'finished_at' => now(), 'error' => $error]);
    }

    private function since(ExternalSource $source): ?CarbonImmutable
    {
        $since = $source->sync_cursor['since'] ?? null;

        return is_string($since) ? CarbonImmutable::parse($since) : null;
    }
}
