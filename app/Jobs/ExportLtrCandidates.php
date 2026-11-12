<?php

namespace App\Jobs;

use App\Discovery\Recommendations\CandidatePool;
use App\Discovery\Recommendations\HybridRanker;
use App\Discovery\Recommendations\RankedItem;
use App\Discovery\Recommendations\RankingWeights;
use App\Discovery\Recommendations\SeedItems;
use App\Models\LtrCandidate;
use App\Models\ModelVersion;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The nightly candidate export of learning to rank (P8-R2): for each active, eligible reader the top `LIMIT` titles the hybrid ranker would pick,
 * with the features it gave them, replacing that reader's rows in `ltr_candidates`. Python scores them and never recomputes a feature. It does
 * nothing unless an `ltr` version is active, and it ranks only what the reader may see (the pool and the ranker go through `Media::visibleTo`).
 * When it has written rows it queues the `ltr-scores` pipeline, so scoring starts once the export is complete and never on a half-written one.
 *
 * Active means a `user_interactions` event in the last `ACTIVE_DAYS`, not deactivated, personalization on.
 */
class ExportLtrCandidates implements ShouldQueue
{
    use Queueable;

    /** The candidates exported per reader. */
    public const LIMIT = 300;

    /** A reader with no interaction this recent is not exported. */
    public const ACTIVE_DAYS = 30;

    /** Readers per query chunk. */
    private const CHUNK = 100;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(CandidatePool $pool, HybridRanker $ranker, SeedItems $seeds): void
    {
        // Cleared before the version check, so rows left by a retired `ltr` version do not outlive it. What no longer gets rewritten
        // (a reader gone quiet or opted out) would only go stale; Python ignores it after 36 hours anyway.
        LtrCandidate::query()->where('generated_at', '<', now()->subHours(48))->delete();

        if (ModelVersion::activeFor('ltr') === null) {
            return;
        }

        $written = 0;

        $this->readers()->chunkById(self::CHUNK, function ($readers) use ($pool, $ranker, $seeds, &$written): void {
            foreach ($readers as $reader) {
                $written += $this->export($reader, $pool, $ranker, $seeds);
            }
        });

        if ($written > 0) {
            RunMlJob::dispatchPipeline('ltr-scores');
        }
    }

    /**
     * @return Builder<User>
     */
    private function readers(): Builder
    {
        return User::query()->with('profile')->whereNull('deactivated_at')
            ->whereHas('profile', fn ($profile) => $profile->where('personalization_enabled', true))
            ->whereExists(fn ($events) => $events->selectRaw('1')->from('user_interactions')
                ->whereColumn('user_interactions.user_id', 'users.id')->where('user_interactions.occurred_at', '>=', now()->subDays(self::ACTIVE_DAYS)));
    }

    private function export(User $reader, CandidatePool $pool, HybridRanker $ranker, SeedItems $seeds): int
    {
        $ranked = $seeds->during(function () use ($reader, $pool, $ranker): Collection {
            return $ranker->rank($reader, $pool->gather($reader, self::LIMIT), RankingWeights::active())->take(self::LIMIT);
        });

        $now = now();
        $rows = $ranked->map(fn (RankedItem $item): array => [
            'user_id' => $reader->id,
            'media_id' => $item->mediaId,
            'features' => json_encode((object) $item->features, JSON_THROW_ON_ERROR),
            'generated_at' => $now,
        ])->all();

        DB::transaction(function () use ($reader, $rows): void {
            LtrCandidate::query()->where('user_id', $reader->id)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                LtrCandidate::query()->insert($chunk);
            }
        });

        return count($rows);
    }
}
