<?php

namespace App\Console\Commands;

use App\Catalog\Classification\Reclassifier;
use App\Catalog\Classification\ReclassifyOutcome;
use App\Jobs\ClassifyMedia;
use App\Models\ExternalSource;
use App\Models\Media;
use App\Models\MediaExternalId;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

#[Signature('bookflix:reclassify {--source= : Only media that came from this provider key, e.g. openlibrary} {--dry-run : Report what would change and write nothing} {--resync : Clear the source\'s payload hashes so its next full sync re-imports every record (needs --source)} {--min-confidence=0.6 : Do not change a media type the classifier is less sure of than this}')]
#[Description('Re-derive media types, genres, themes, tropes, demographics, ratings and warnings from what is stored')]
class ReclassifyMedia extends Command
{
    /**
     * Execute the console command.
     *
     * Each media is re-derived with the importers' own classifier and taxonomy mapper (see Reclassifier); admin locks are respected, and media whose
     * type changed are queued for Dewey classification again. Stored data cannot always beat the provider's record (Open Library keeps no page
     * format, Hardcover no publisher), so `--resync --source=x` clears that source's payload hashes: `bookflix:sync x --full` then re-imports everything.
     */
    public function handle(Reclassifier $reclassifier): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $source = $this->option('source');
        $source = is_string($source) && $source !== '' ? $source : null;
        $minConfidence = (float) $this->option('min-confidence');

        if ($source !== null && ! ExternalSource::where('key', $source)->exists()) {
            $this->error("Unknown source {$source}.");

            return self::FAILURE;
        }

        if ($this->option('resync') && $source === null) {
            $this->error('--resync needs --source, so one provider is re-imported at a time.');

            return self::FAILURE;
        }

        $outcomes = [];
        $query = Media::query()
            ->when($source !== null, fn (Builder $media) => $media->whereHas('externalIds', fn (Builder $ids) => $ids->whereIn('external_source_id', ExternalSource::where('key', $source)->select('id'))));

        $query->lazyById(200)->each(function (Media $media) use ($reclassifier, $source, $dryRun, $minConfidence, &$outcomes): void {
            $outcomes[] = DB::transaction(fn (): ReclassifyOutcome => $reclassifier->reclassify($media, $source, ! $dryRun, $minConfidence));
        });

        $this->report($outcomes, $dryRun);

        $changed = array_values(array_filter($outcomes, fn (ReclassifyOutcome $outcome): bool => $outcome->typeChanged()));

        if (! $dryRun) {
            foreach ($changed as $outcome) {
                ClassifyMedia::dispatch($outcome->mediaId);
            }
        }

        $this->line(($dryRun ? 'Would queue' : 'Queued').' Dewey classification again for '.count($changed).' media whose type changed.');

        if ($this->option('resync') && $source !== null) {
            $this->resync($source, $dryRun);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<ReclassifyOutcome>  $outcomes
     */
    private function report(array $outcomes, bool $dryRun): void
    {
        $verb = $dryRun ? 'Would change' : 'Changed';
        $transitions = [];

        foreach ($outcomes as $outcome) {
            $key = "{$outcome->oldType} → {$outcome->newType}";
            $transitions[$key] = ($transitions[$key] ?? 0) + 1;
        }

        ksort($transitions);
        $this->newLine();
        $this->info($dryRun ? 'Dry run: nothing was written.' : 'Done.');
        $this->line('Looked at '.count($outcomes).' media; '.count(array_filter($outcomes, fn (ReclassifyOutcome $outcome): bool => $outcome->changedAnything())).' changed.');
        $this->table(['Old type', 'New type', 'Media'], array_map(fn (string $key, int $count): array => [...explode(' → ', $key), $count], array_keys($transitions), $transitions));
        $this->line(count(array_filter($outcomes, fn (ReclassifyOutcome $outcome): bool => $outcome->typeChanged())) === 0 ? 'No media type changed.' : 'Rows whose old and new type differ were '.($dryRun ? 'not ' : '').'corrected.');

        $sum = fn (string $key): int => array_sum(array_map(fn (ReclassifyOutcome $outcome): int => $outcome->attached[$key], $outcomes));

        $this->table(['Taxonomy', $verb], [
            ['Genres attached', $sum('genres')],
            ['Themes attached', $sum('themes')],
            ['Tropes attached', $sum('tropes')],
            ['Demographics attached', $sum('demographics')],
            ['Content warnings recorded as present', $sum('warnings')],
            ['Audience ratings set (were unknown)', count(array_filter($outcomes, fn (ReclassifyOutcome $outcome): bool => $outcome->ratingSet))],
            ['Tags folded into the above', array_sum(array_map(fn (ReclassifyOutcome $outcome): int => $outcome->tagsFolded, $outcomes))],
        ]);

        $prohibited = array_filter($outcomes, fn (ReclassifyOutcome $outcome): bool => $outcome->prohibited);

        if ($prohibited !== []) {
            $this->warn(($dryRun ? 'Would remove ' : 'Removed ').count($prohibited).' media labelled with terms for sexual content involving minors, which the catalogue never holds (soft deleted, ids: '.implode(', ', array_map(fn (ReclassifyOutcome $outcome): int => $outcome->mediaId, $prohibited)).').');
        }

        $unsure = array_filter($outcomes, fn (ReclassifyOutcome $outcome): bool => ! $outcome->typeChanged() && $outcome->confidence < (float) $this->option('min-confidence'));

        if ($unsure !== []) {
            $this->line(count($unsure).' media have a type the classifier doubts but not enough to change (see --min-confidence).');
        }
    }

    private function resync(string $source, bool $dryRun): void
    {
        $rows = MediaExternalId::query()->whereIn('external_source_id', ExternalSource::where('key', $source)->select('id'));
        $count = $rows->count();

        if (! $dryRun) {
            $rows->update(['payload_hash' => str_repeat('0', 64)]);
        }

        $this->line(($dryRun ? 'Would clear' : 'Cleared')." the payload hash of {$count} {$source} record(s). Run `php artisan bookflix:sync {$source} --full` to re-import them with the current classifier and mappings.");
    }
}
