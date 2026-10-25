<?php

namespace App\Jobs;

use App\Catalog\Ingestion\IngestionPipeline;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Enums\IngestionMode;
use App\Enums\IngestionStatus;
use App\Models\IngestionRun;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Str;
use Throwable;

class FetchProviderPage implements ShouldQueue
{
    use Queueable;

    /**
     * Rate-limit releases still count as attempts, so a `$tries` cap would fail a page that only waited its turn.
     * Time bounds the waiting instead and only real exceptions are capped.
     */
    public int $maxExceptions = 5;

    public function __construct(
        public int $ingestionRunId,
        public ?string $pageToken = null,
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
     * @return list<RateLimited>
     */
    public function middleware(): array
    {
        $key = $this->run()?->externalSource->key;

        return $key === null ? [] : [new RateLimited("provider:{$key}")];
    }

    /**
     * Fetch one page, import its records inline, write the page's counts and next token to the run, then queue the next page
     * or close the run. A 429 releases this job; any other exception is retried by the queue with the same run and the same page,
     * and `failed()` closes the run once the retries are spent. Importing needs no I/O, so it is not worth a job per record.
     */
    public function handle(IngestionPipeline $pipeline): void
    {
        $run = $this->run();

        if ($run === null || $run->status !== IngestionStatus::Running) {
            return;
        }

        $provider = app(config("bookflix.providers.{$run->externalSource->key}.class"));

        try {
            $page = $provider->page($this->since($run), $this->pageToken);
        } catch (RateLimitedByProvider $exception) {
            $this->release($exception->retryAfter);

            return;
        }

        $counts = ['fetched' => count($page->records), 'created' => 0, 'updated' => 0, 'merged' => 0, 'skipped' => 0, 'failed' => 0];
        $failedIds = [];

        foreach ($page->records as $record) {
            try {
                $counts[$pipeline->import($record, $provider)->value]++;
            } catch (Throwable $exception) {
                report($exception);
                $counts['failed']++;
                $failedIds[] = ['id' => $record->externalId, 'error' => Str::limit($exception->getMessage(), 300, '...')];
            }
        }

        $run->recordPage($counts, $failedIds, $page->nextPageToken, $page->maxUpdatedAt);

        if ($run->page_token !== null && $run->pages < (int) config("bookflix.providers.{$run->externalSource->key}.max_pages_per_run", 20)) {
            self::dispatch($run->id, $run->page_token);

            return;
        }

        $run->finish();
    }

    public function failed(Throwable $exception): void
    {
        $this->run()?->fail($exception->getMessage());
    }

    private function run(): ?IngestionRun
    {
        return IngestionRun::with('externalSource')->find($this->ingestionRunId);
    }

    private function since(IngestionRun $run): ?CarbonImmutable
    {
        $since = $run->mode === IngestionMode::Full ? null : ($run->externalSource->sync_cursor['since'] ?? null);

        return is_string($since) ? CarbonImmutable::parse($since) : null;
    }
}
