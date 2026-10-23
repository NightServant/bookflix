<?php

namespace App\Jobs;

use App\Catalog\Data\ProviderRecord;
use App\Catalog\Ingestion\IngestionPipeline;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Models\IngestionRun;
use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

class ImportMediaRecord implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Rate-limit releases still count as attempts, so a `$tries` cap would fail jobs that only waited their turn.
     * Time bounds the waiting instead and only real exceptions are capped.
     */
    public int $maxExceptions = 5;

    public function __construct(
        public ProviderRecord $record,
        public string $providerKey,
        public ?int $ingestionRunId = null,
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
        return [new RateLimited("provider:{$this->providerKey}")];
    }

    public function handle(IngestionPipeline $pipeline): void
    {
        try {
            $outcome = $pipeline->import($this->record, app(config("bookflix.providers.{$this->providerKey}.class")));
        } catch (RateLimitedByProvider $exception) {
            $this->release($exception->retryAfter);

            return;
        }

        $this->run()?->bump($outcome->value);
    }

    public function failed(Throwable $exception): void
    {
        $this->run()?->bump('failed');
    }

    private function run(): ?IngestionRun
    {
        return $this->ingestionRunId ? IngestionRun::find($this->ingestionRunId) : null;
    }
}
