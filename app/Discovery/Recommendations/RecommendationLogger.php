<?php

namespace App\Discovery\Recommendations;

use App\Enums\RecommendationEventType;
use App\Events\RecommendationDismissed;
use App\Models\ModelVersion;
use App\Models\Recommendation;
use App\Models\RecommendationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keeps what was shown to a reader (with the score, the reason and the ranker's features at that moment) and what they did with it.
 * A reader who opted out of personalization produces neither rows nor events.
 */
class RecommendationLogger
{
    /**
     * Writes one `recommendations` row per served title and returns their ids keyed `{shelf}:{media id}`. Nothing is written for an opted-out reader
     * or without an active `hybrid-weights` version to attribute the rows to (the column is required).
     *
     * @param  iterable<ServedItem>  $served
     * @return array<string, int>
     */
    public function log(User $user, iterable $served, ?ModelVersion $version): array
    {
        if (! $user->personalizationEnabled() || $version === null) {
            return [];
        }

        $now = now()->startOfSecond();
        $rows = [];

        foreach ($served as $item) {
            $rows[] = [
                'user_id' => $user->id,
                'media_id' => $item->mediaId,
                'shelf' => $item->shelf,
                'score' => $item->score,
                'reason_type' => $item->reason->value,
                'reason_media_id' => $item->reasonMediaId,
                'reason_payload' => json_encode(['features' => (object) $item->features] + $item->payload, JSON_THROW_ON_ERROR),
                'model_version_id' => $version->id,
                'generated_at' => $now,
            ];
        }

        if ($rows === []) {
            return [];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('recommendations')->insert($chunk);
        }

        // A second fill in the same instant adds rows for the same pairs; the newest id of each pair is the one served.
        return DB::table('recommendations')
            ->where('user_id', $user->id)->where('generated_at', $now)
            ->selectRaw('shelf, media_id, max(id) as id')->groupBy('shelf', 'media_id')
            ->get()
            ->mapWithKeys(fn (object $row): array => ["{$row->shelf}:{$row->media_id}" => (int) $row->id])
            ->all();
    }

    /**
     * Records what happened to a recommendation. Only its owner can: a foreign or unknown id is ignored, so an id guessed from a link changes nothing.
     * A dismissal also clears the reader's cached shelves, so the title is gone on the next view.
     */
    public function event(User $user, int $recommendationId, RecommendationEventType $event): void
    {
        if (! $user->personalizationEnabled()) {
            return;
        }

        $recommendation = Recommendation::query()->whereKey($recommendationId)->where('user_id', $user->id)->first();

        if ($recommendation === null) {
            return;
        }

        RecommendationEvent::query()->create(['recommendation_id' => $recommendation->id, 'event' => $event, 'occurred_at' => now()]);

        if ($event === RecommendationEventType::Dismiss) {
            RecommendationDismissed::dispatch($user->id);
        }
    }
}
