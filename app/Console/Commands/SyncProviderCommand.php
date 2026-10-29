<?php

namespace App\Console\Commands;

use App\Enums\IngestionMode;
use App\Enums\IngestionStatus;
use App\Jobs\SyncProvider;
use App\Models\ExternalSource;
use App\Models\IngestionRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookflix:sync {provider : The provider key, e.g. anilist} {--full : Ignore the sync cursor and fetch everything} {--force : Sync even though the source is disabled}')]
#[Description('Open a sync run for a metadata provider and queue its pages')]
class SyncProviderCommand extends Command
{
    /**
     * Execute the console command. The run is opened here and its pages go to the ingestion queue, one rate-limited job per page.
     * A disabled source is refused unless `--force` is given; `external_sources.enabled` is the one source of truth.
     */
    public function handle(): int
    {
        $provider = $this->argument('provider');

        if (! is_string($provider) || config("bookflix.providers.{$provider}.class") === null) {
            $this->error('Unknown provider. Known: '.implode(', ', array_keys(array_filter(config('bookflix.providers'), fn (array $config): bool => $config['class'] !== null))).'.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! ExternalSource::providers()->where('key', $provider)->where('enabled', true)->exists()) {
            $this->error("The {$provider} source is disabled. Enable it on the Providers page, or pass --force.");

            return self::FAILURE;
        }

        $lastRunId = (int) IngestionRun::max('id');

        SyncProvider::dispatchSync($provider, $this->option('full') ? IngestionMode::Full : IngestionMode::Incremental);

        $run = IngestionRun::where('id', '>', $lastRunId)->first();

        if ($run === null) {
            $this->warn("A {$provider} sync is already running.");

            return self::SUCCESS;
        }

        $this->info($run->status === IngestionStatus::Running
            ? "Run #{$run->id} started; its pages are queued on the ingestion queue."
            : "Run #{$run->id} is {$run->status->value}; {$run->counts['fetched']} record(s) fetched.");

        return $run->status === IngestionStatus::Failed ? self::FAILURE : self::SUCCESS;
    }
}
