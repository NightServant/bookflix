<?php

namespace App\Jobs;

use App\Enums\IngestionMode;
use App\Enums\IngestionStatus;
use App\Models\ExternalSource;
use App\Models\IngestionRun;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncProvider implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Hours without progress after which a run still marked running is taken to be dead. Longer than the two-hour retry window of a page.
     */
    private const STALE_AFTER_HOURS = 3;

    /**
     * Rate-limit releases still count as attempts, so a `$tries` cap would fail a sync that only waited its turn.
     * Time bounds the waiting instead and only real exceptions are capped.
     */
    public int $maxExceptions = 5;

    /**
     * A queued or running sync for a provider blocks another one; the lock expires with the retry window, so a crashed worker cannot block it forever.
     */
    public int $uniqueFor = 7200;

    public function __construct(
        public string $providerKey,
        public IngestionMode $mode = IngestionMode::Incremental,
    ) {
        $this->onQueue('ingestion');
    }

    public function uniqueId(): string
    {
        return $this->providerKey;
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
     * Open the run and hand it to the first page job; the pages do the fetching and importing. A run still in flight for this provider
     * (the schedule can outpace a long sync) is left alone, and a failed or cut-short one is continued from the page it stopped at,
     * unless a full sync was asked for. A failure to hand over closes the run it opened and is reported, not retried, so a retry never opens a second row.
     */
    public function handle(): void
    {
        $source = ExternalSource::where('key', $this->providerKey)->firstOrFail();

        IngestionRun::where('external_source_id', $source->id)->where('status', IngestionStatus::Running)
            ->where('updated_at', '<', now()->subHours(self::STALE_AFTER_HOURS))
            ->each(fn (IngestionRun $stale) => $stale->fail('The run stopped making progress.'));

        if (IngestionRun::where('external_source_id', $source->id)->where('status', IngestionStatus::Running)->exists()) {
            return;
        }

        $resume = $this->mode === IngestionMode::Full ? null : ($source->sync_cursor['resume'] ?? null);
        $run = IngestionRun::create([
            'external_source_id' => $source->id,
            'mode' => $resume ? IngestionMode::from($resume['mode']) : $this->mode,
            'started_at' => now(),
            'page_token' => $resume['page_token'] ?? null,
            'max_updated_at' => $resume['max_updated_at'] ?? null,
            'walk_started_at' => $resume['walk_started_at'] ?? now(),
        ]);

        try {
            FetchProviderPage::dispatch($run->id, $run->page_token);
        } catch (Throwable $exception) {
            report($exception);
            $run->fail($exception->getMessage());
        }
    }

    /**
     * The job gave up (retries spent or killed): close whatever run it opened before any page recorded progress.
     * A run that already fetched a page belongs to a live page job, which closes it itself.
     */
    public function failed(Throwable $exception): void
    {
        IngestionRun::whereHas('externalSource', fn ($query) => $query->where('key', $this->providerKey))
            ->where('status', IngestionStatus::Running)->where('pages', 0)
            ->each(fn (IngestionRun $run) => $run->fail($exception->getMessage()));
    }
}
