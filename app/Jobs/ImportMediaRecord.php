<?php

namespace App\Jobs;

use App\Catalog\Data\ProviderRecord;
use App\Catalog\Ingestion\IngestionPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

class ImportMediaRecord implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public ProviderRecord $record,
        public string $providerKey,
        public ?int $ingestionRunId = null,
    ) {
        $this->onQueue('ingestion');
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
        $pipeline->import($this->record, app(config("bookflix.providers.{$this->providerKey}.class")));
    }
}
