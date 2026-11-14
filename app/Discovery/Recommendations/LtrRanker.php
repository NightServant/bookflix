<?php

namespace App\Discovery\Recommendations;

use App\Models\LtrScore;
use App\Models\ModelVersion;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Re-scores the hybrid ranker's items with the scores the Python service precomputed for this reader (P8-R2). It never ranks anything itself and
 * never adds a title: it only reorders the items it is given, which the hybrid ranker already limited to what the reader may see.
 *
 * It answers null, meaning "use the hybrid order unchanged", when there is no active `ltr` version, or the reader has no score under that
 * version newer than `FRESH_HOURS` (a retired or older version's scores are never read), or none of its scores is for a title it is given. Otherwise the scored titles come first by stored score
 * and the unscored ones follow in their hybrid order.
 */
class LtrRanker
{
    /** The oldest a stored score may be and still count. */
    public const FRESH_HOURS = 36;

    /**
     * @param  Collection<int, RankedItem>  $ranked  the hybrid order, best first
     * @return Collection<int, RankedItem>|null
     */
    public function rank(User $user, Collection $ranked): ?Collection
    {
        $version = $this->version();

        if ($version === null) {
            return null;
        }

        $scores = LtrScore::query()
            ->where('user_id', $user->id)->where('model_version_id', $version->id)
            ->where('generated_at', '>=', now()->subHours(self::FRESH_HOURS))
            ->whereIn('media_id', $ranked->pluck('mediaId')->all())
            ->pluck('score', 'media_id');

        if ($scores->isEmpty() && ! $this->hasFreshScores($user, $version)) {
            return null;
        }

        $scored = $ranked->filter(fn (RankedItem $item): bool => $scores->has($item->mediaId));

        // Fresh scores that match none of these titles reorder nothing: null, so the shelf is served and logged as the hybrid order it is.
        if ($scored->isEmpty()) {
            return null;
        }

        $low = (float) $scores->min();
        $span = (float) $scores->max() - $low;

        // Scored titles map into [0.5, 1] and unscored ones below 0.5, so the score order is the shown order and the diversifier cannot put an
        // unscored title above a scored one.
        $items = $ranked->map(fn (RankedItem $item): RankedItem => $this->withScore(
            $item,
            $scores->has($item->mediaId)
                ? 0.5 + 0.5 * ($span > 0 ? ((float) $scores[$item->mediaId] - $low) / $span : 1.0)
                : 0.49 * $item->score,
        ));

        $scoredItems = $items->filter(fn (RankedItem $item): bool => $scores->has($item->mediaId))
            ->sortBy(fn (RankedItem $item): array => [-$item->score, $item->mediaId]);

        return $scoredItems->concat($items->reject(fn (RankedItem $item): bool => $scores->has($item->mediaId)))->values();
    }

    /**
     * The active `ltr` version, or null.
     */
    public function version(): ?ModelVersion
    {
        return ModelVersion::activeFor('ltr');
    }

    private function hasFreshScores(User $user, ModelVersion $version): bool
    {
        return LtrScore::query()->where('user_id', $user->id)->where('model_version_id', $version->id)
            ->where('generated_at', '>=', now()->subHours(self::FRESH_HOURS))->exists();
    }

    private function withScore(RankedItem $item, float $score): RankedItem
    {
        return new RankedItem($item->mediaId, min(1.0, max(0.0, $score)), $item->features, $item->explanation, $item->reasonMediaId);
    }
}
