<?php

namespace App\Console\Commands;

use App\Jobs\RunMlJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('bookflix:ltr-readiness')]
#[Description('Show how many labeled impressions there are for learning to rank against the number it needs to train')]
class LtrReadiness extends Command
{
    /** The days of the log the training export reads (the Python service's `impressions.RETENTION_DAYS`). */
    public const RETENTION_DAYS = 90;

    /** The days after an impression its outcome is watched; an impression younger than that is not labeled yet (`impressions.WINDOW_DAYS`). */
    public const WINDOW_DAYS = 7;

    /**
     * Labeled impressions as the Python export defines them (`impressions.fetch_labeled`): one per logged recommendation that has an impression
     * event however often it repeated, has a place in its shelf, was generated within the last `RETENTION_DAYS`, was first shown at least
     * `WINDOW_DAYS` ago, and belongs to a reader who is not deactivated and has personalization on, for a title that is still live.
     * The two must stay the same: a test builds one fixture and expects both to count it alike.
     */
    public static function count(): int
    {
        return DB::table('recommendations as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('profiles as p', 'p.user_id', '=', 'u.id')
            ->join('media as m', 'm.id', '=', 'r.media_id')
            ->whereNotNull('r.position')
            ->where('r.generated_at', '>=', now()->subDays(self::RETENTION_DAYS))
            ->whereNull('u.deactivated_at')
            ->whereRaw('coalesce(p.personalization_enabled, true)')
            ->whereNull('m.deleted_at')->whereNull('m.merged_into_id')
            ->whereRaw("(select min(e.occurred_at) from recommendation_events e where e.recommendation_id = r.id and e.event = 'impression') <= ?", [now()->subDays(self::WINDOW_DAYS)])
            ->count();
    }

    public function handle(): int
    {
        $have = self::count();
        $need = RunMlJob::LTR_MIN_LABELED;

        $this->info(sprintf('Labeled impressions: %s of %s needed (%s).', number_format($have), number_format($need), $have >= $need ? 'ready to train' : 'not enough yet'));

        return self::SUCCESS;
    }
}
