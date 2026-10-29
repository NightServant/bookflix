<?php

namespace App\Jobs;

use App\Catalog\MediaRatings;
use App\Models\Media;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Fold the readers' scores for one story into its rating (D-36). The scores of the active readers (a deactivated reader counts for nothing) become
 * the synthetic `bookflix` source's two metrics in `media_metrics`: `ratings_count`, and `average_rating` on the 0 to 5 scale every source uses (the
 * mean of the 1 to 10 scores, halved). With no active reader's score the two rows are deleted. Then the story's columns are blended again from all
 * its sources (see MediaRatings::blend), so the providers' ratings stay in. The story is locked while it runs, so two runs cannot cross.
 * Safe to run any number of times.
 */
class RecalculateMediaRating implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $mediaId) {}

    public function handle(): void
    {
        $source = MediaRatings::readersSource();

        DB::transaction(function () use ($source): void {
            $media = Media::query()->lockForUpdate()->find($this->mediaId);

            if ($media === null) {
                return;
            }

            $readers = Rating::query()
                ->where('media_id', $media->id)
                ->whereIn('user_id', User::query()->active()->select('id'))
                ->toBase()
                ->selectRaw('count(*) as total, avg(score) as mean')
                ->sole();

            if ((int) $readers->total === 0) {
                DB::table('media_metrics')->where('media_id', $media->id)->where('external_source_id', $source->id)->whereIn('metric', MediaRatings::METRICS)->delete();
            } else {
                DB::table('media_metrics')->upsert(
                    [
                        ['media_id' => $media->id, 'external_source_id' => $source->id, 'metric' => 'ratings_count', 'value' => (int) $readers->total, 'observed_at' => now()],
                        ['media_id' => $media->id, 'external_source_id' => $source->id, 'metric' => 'average_rating', 'value' => round((float) $readers->mean / 2, 2), 'observed_at' => now()],
                    ],
                    ['media_id', 'external_source_id', 'metric'],
                    ['value', 'observed_at'],
                );
            }

            MediaRatings::blend($media, clearEmpty: true);
        });
    }
}
