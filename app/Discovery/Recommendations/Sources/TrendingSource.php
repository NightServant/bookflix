<?php

namespace App\Discovery\Recommendations\Sources;

use App\Discovery\Recommendations\Candidate;
use App\Enums\ReasonType;
use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What is popular now, in the order and with the blend of `Media::scopeTrending` (provider popularity plus a small share of Bookflix's own
 * activity), so a catalog with no interactions still has candidates. The scores are the blend divided by the best one in the set; when the
 * blend is zero everywhere (no provider data either) they fall back to the position in the scope's order.
 */
class TrendingSource extends Source
{
    public function key(): string
    {
        return 'trending';
    }

    protected function gather(User $user, int $limit): Collection
    {
        $rows = $this->eligible($user)->trending()
            ->select('media.id')
            ->selectRaw(Media::TRENDING_BLEND.' as blend', [Media::trendingWeight()])
            ->limit($limit)
            ->get();

        $best = (float) $rows->max(fn (Media $media) => $media->getAttribute('blend'));

        if ($best <= 0) {
            return $this->byPosition(array_values(array_map(intval(...), $rows->pluck('id')->all())), ReasonType::Trending);
        }

        return $rows->map(fn (Media $media): Candidate => new Candidate($media->id, $this->key(), (float) $media->getAttribute('blend') / $best, ReasonType::Trending))->values();
    }
}
