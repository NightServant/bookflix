<?php

use App\Jobs\ExportLtrCandidates;
use App\Jobs\RunMlJob;
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
Schedule::command('bookflix:prune-exports')->daily();
Schedule::command('bookflix:score-popularity')->daily();
Schedule::command('bookflix:sync-signals')->daily();
Schedule::command('model:prune')->daily();
// At :20, clear of the minutes the ML pipelines take; the shelf order reads only what this writes.
Schedule::command('bookflix:refresh-shelf-stats')->hourlyAt(20);

$sourceIsEnabled = fn (string $key): Closure => fn (): bool => ExternalSource::where('key', $key)->where('enabled', true)->exists();

Schedule::job(new SyncProvider('openlibrary'))->name('sync:openlibrary')->hourly()->when($sourceIsEnabled('openlibrary'));
Schedule::job(new SyncProvider('anilist'))->name('sync:anilist')->everySixHours()->when($sourceIsEnabled('anilist'));
Schedule::job(new SyncProvider('mangadex'))->name('sync:mangadex')->everySixHours()->when($sourceIsEnabled('mangadex'));
Schedule::job(new SyncProvider('mangaupdates'))->name('sync:mangaupdates')->everySixHours()->when($sourceIsEnabled('mangaupdates'));
Schedule::job(new SyncProvider('comicvine'))->name('sync:comicvine')->daily()->when($sourceIsEnabled('comicvine'));
Schedule::job(new SyncProvider('metron'))->name('sync:metron')->everySixHours()->when($sourceIsEnabled('metron'));
Schedule::job(new SyncProvider('hardcover'))->name('sync:hardcover')->everySixHours()->when($sourceIsEnabled('hardcover'));

// The ML pipelines (train, build similarities, evaluate), each a chain that stops at its first failed step. They wait for a configured service, and a pipeline still running from the last time is not started again.
$mlIsConfigured = fn (): bool => (string) config('services.ml.token') !== '';

Schedule::call(fn () => RunMlJob::dispatchPipeline('popularity'))->name('ml:popularity')->hourly()->when($mlIsConfigured);
Schedule::call(fn () => RunMlJob::dispatchPipeline('item-cf'))->name('ml:item-cf')->dailyAt('03:00')->when($mlIsConfigured);
Schedule::call(fn () => RunMlJob::dispatchPipeline('lsa'))->name('ml:lsa')->weeklyOn(0, '02:00')->when($mlIsConfigured);
// Half past, so it does not queue behind the popularity refresh on the service's one worker.
Schedule::call(fn () => RunMlJob::dispatchPipeline('embed'))->name('ml:embed')->hourlyAt(30)->when($mlIsConfigured);
// Nightly, after the item-cf training, so the embeddings of the day's new media have neighbors by morning.
Schedule::call(fn () => RunMlJob::dispatchPipeline('embedding-similarities'))->name('ml:embedding-similarities')->dailyAt('04:15')->when($mlIsConfigured);
// After the embedding similarities and before the Sunday classifier; skips cleanly until there are enough interactions.
Schedule::call(fn () => RunMlJob::dispatchPipeline('als'))->name('ml:als')->dailyAt('04:40')->when($mlIsConfigured);
// Weekly, Sunday morning, three hours after the weekly LSA run starts: the classifier is tied to the LSA version it was trained on, so it retrains
// the same morning. It skips cleanly until reviewers have accepted enough primary classes.
Schedule::call(fn () => RunMlJob::dispatchPipeline('dewey-classifier'))->name('ml:dewey-classifier')->weeklyOn(0, '05:10')->when($mlIsConfigured);
// Nightly, once the morning's ML jobs are done: the export waits for no service (it is queued PHP work) and queues the scoring pipeline when it has written its rows.
// It does nothing until an `ltr` version is active.
Schedule::job(new ExportLtrCandidates)->name('ltr:export-candidates')->dailyAt('05:30')->when($mlIsConfigured);
// Weekly, Sunday after the Dewey classifier (05:10) has had its time, so the one worker is not asked for two trainings at once. It skips cleanly until there are enough labeled impressions.
Schedule::call(fn () => RunMlJob::dispatchPipeline('ltr'))->name('ml:ltr')->weeklyOn(0, '06:10')->when($mlIsConfigured);
