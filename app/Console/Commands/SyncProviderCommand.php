<?php

namespace App\Console\Commands;

use App\Enums\IngestionMode;
use App\Enums\IngestionStatus;
use App\Jobs\SyncProvider;
use App\Models\IngestionRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookflix:sync {provider : The provider key, e.g. anilist} {--full : Ignore the sync cursor and fetch everything}')]
#[Description('Fetch a metadata provider now and queue its records for import')]
class SyncProviderCommand extends Command
{
    /**
     * Execute the console command. The fetch runs here, in the foreground, so a long `--full` is not cut short by a queue timeout;
     * the imports themselves go to the ingestion queue.
     */
    public function handle(): int
    {
        $provider = $this->argument('provider');

        if (! is_string($provider) || config("bookflix.providers.{$provider}") === null) {
            $this->error('Unknown provider. Known: '.implode(', ', array_keys(config('bookflix.providers'))).'.');

            return self::FAILURE;
        }

        SyncProvider::dispatchSync($provider, $this->option('full') ? IngestionMode::Full : IngestionMode::Incremental);

        $run = IngestionRun::latest('id')->first();
        $this->info("Run #{$run->id} is {$run->status->value}; {$run->counts['fetched']} record(s) fetched.");

        return $run->status === IngestionStatus::Failed ? self::FAILURE : self::SUCCESS;
    }
}
