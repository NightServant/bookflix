<?php

use App\Jobs\SyncProvider;
use App\Models\ExternalSource;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bookflix:purge-deactivated-accounts')->daily();
Schedule::command('bookflix:prune-covers')->weekly();
Schedule::command('bookflix:score-popularity')->daily();

$sourceIsEnabled = fn (string $key): Closure => fn (): bool => ExternalSource::where('key', $key)->where('enabled', true)->exists();

Schedule::job(new SyncProvider('openlibrary'))->name('sync:openlibrary')->hourly()->when($sourceIsEnabled('openlibrary'));
Schedule::job(new SyncProvider('anilist'))->name('sync:anilist')->everySixHours()->when($sourceIsEnabled('anilist'));
