<?php

namespace App\Discovery\Recommendations;

use App\Enums\RecommendationEventType;
use App\Events\RecommendationDismissed;
use App\Models\Media;
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

    /**
     * A click on a tile. Returns the recommendation, or null when it is not the reader's (or they opted out), so the caller records nothing more.
     * Nothing is kept in the session for a later "Add to Library": the story's page request starts before the click is sent, and the session
     * saved at the end of each request would overwrite the other's. The click event is what `libraryAdd` finds again.
     */
    public function click(User $user, int $recommendationId): ?Recommendation
    {
        $recommendation = $this->owned($user, $recommendationId);

        if ($recommendation === null) {
            return null;
        }

        $this->event($user, $recommendation->id, RecommendationEventType::Click);

        return $recommendation;
    }

    /**
     * The story was added to the library from its page: the reader's latest click on a tile of it within `library_add_window_minutes`, that has no
     * library add yet, led to this one. The caller says so only when the entry was newly created, so two adds record once.
     */
    public function libraryAdd(User $user, Media $media): void
    {
        if (! $user->personalizationEnabled()) {
            return;
        }

        $window = now()->subMinutes((int) config('bookflix.recommendations.library_add_window_minutes'));

        $click = RecommendationEvent::query()
            ->join('recommendations', 'recommendations.id', '=', 'recommendation_events.recommendation_id')
            ->where('recommendations.user_id', $user->id)->where('recommendations.media_id', $media->id)
            ->where('recommendation_events.event', RecommendationEventType::Click)->where('recommendation_events.occurred_at', '>=', $window)
            ->whereNotExists(fn ($added) => $added->selectRaw('1')->from('recommendation_events as added')
                ->whereColumn('added.recommendation_id', 'recommendation_events.recommendation_id')->where('added.event', RecommendationEventType::LibraryAdd))
            ->orderByDesc('recommendation_events.occurred_at')->orderByDesc('recommendation_events.id')
            ->first(['recommendation_events.recommendation_id']);

        if ($click !== null) {
            $this->event($user, $click->recommendation_id, RecommendationEventType::LibraryAdd);
        }
    }

    /**
     * Tiles that came into view, in one query and one insert. Only the reader's own are kept.
     *
     * @param  list<int>  $recommendationIds
     */
    public function impressions(User $user, array $recommendationIds): void
    {
        if (! $user->personalizationEnabled() || $recommendationIds === []) {
            return;
        }

        $now = now();
        $rows = Recommendation::query()->where('user_id', $user->id)->whereIn('id', array_unique($recommendationIds))->pluck('id')
            ->map(fn (int $id): array => ['recommendation_id' => $id, 'event' => RecommendationEventType::Impression->value, 'occurred_at' => $now])->all();

        if ($rows !== []) {
            RecommendationEvent::query()->insert($rows);
        }
    }

    /**
     * Takes a dismissal back (the undo of the toast): the reader's own dismiss events of that recommendation are deleted and the cached shelves
     * forgotten, so the title can be offered again. Another reader's id, or any other kind of event, is left alone.
     */
    public function undoDismiss(User $user, int $recommendationId): void
    {
        $recommendation = $this->owned($user, $recommendationId);

        if ($recommendation === null) {
            return;
        }

        RecommendationEvent::query()->where('recommendation_id', $recommendation->id)->where('event', RecommendationEventType::Dismiss)->delete();

        RecommendationDismissed::dispatch($user->id);
    }

    /**
     * The reader's own recommendation of that id, or null (also for a reader who opted out): an id that is not theirs says nothing.
     */
    public function owned(User $user, int $recommendationId): ?Recommendation
    {
        return $user->personalizationEnabled()
            ? Recommendation::query()->whereKey($recommendationId)->where('user_id', $user->id)->first()
            : null;
    }
}
