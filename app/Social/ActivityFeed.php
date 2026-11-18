<?php

namespace App\Social;

use App\Enums\ReadingStatus;
use App\Enums\ReviewState;
use App\Enums\Visibility;
use App\Models\Collection as ReaderCollection;
use App\Models\Media;
use App\Models\User;
use App\Support\Markdown;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What the readers the viewer follows have been doing (P10-R13), derived from existing tables with no cache, so a visibility change shows at once:
 * finished a story and rated one (only while the owner's library is public), published a review (spoilers carry a flag and never a body), and
 * made a public collection. Only listed readers count (`User::scopeListed`), and every story goes through `Media::visibleTo`.
 *
 * One UNION ALL query orders the events by (time, type, actor, reference), newest first; the cursor holds the last row's key, so events
 * arriving later never shift the next page. Users, stories, collections and collection covers are then loaded in one query each.
 */
class ActivityFeed
{
    public const EXCERPT_LENGTH = 200;

    public static function for(User $viewer, int $perPage = 20, ?string $cursor = null): ActivityPage
    {
        $perPage = max(1, $perPage);
        $after = self::decode($cursor);

        $rows = DB::query()->fromSub(self::events($viewer), 'e')
            ->when($after !== null, fn (Builder $query) => $query->whereRaw(
                '(e.at, e.kind, e.actor_id, e.ref_id) < (?::timestamp, ?::smallint, ?::bigint, ?::bigint)', $after ?? [],
            ))
            ->orderByDesc('e.at')->orderByDesc('e.kind')->orderByDesc('e.actor_id')->orderByDesc('e.ref_id')
            ->limit($perPage + 1)
            ->get();

        $more = $rows->count() > $perPage;
        $rows = $rows->take($perPage)->values();
        $last = $rows->last();

        return new ActivityPage(
            self::hydrate($viewer, $rows),
            $more && $last !== null ? self::encode($last) : null,
        );
    }

    /**
     * The four kinds of event as one query: at, kind, actor_id, ref_id (a library or review or collection id; the story id for a rating),
     * media_id, and for a review its body (null for a spoiler) and the spoiler flag.
     */
    private static function events(User $viewer): Builder
    {
        $actors = $viewer->listedFollowing()->select('users.id')->toBase();
        $publicLibrary = fn (string $actor) => fn (Builder $profiles) => $profiles->selectRaw('1')->from('profiles')
            ->whereColumn('profiles.user_id', $actor)->where('profiles.library_visibility', Visibility::Public->value);
        $visibleMedia = fn (string $media) => Media::query()->unmerged()->visibleTo($viewer)->selectRaw('1')->whereColumn('media.id', $media)->toBase();

        $finished = DB::table('user_library as l')
            ->whereIn('l.user_id', $actors)
            ->where('l.status', ReadingStatus::Completed->value)->whereNotNull('l.completed_at')
            ->whereExists($publicLibrary('l.user_id'))
            ->whereExists($visibleMedia('l.media_id'))
            ->selectRaw('l.completed_at as at, ?::smallint as kind, l.user_id as actor_id, l.id as ref_id, l.media_id as media_id, null::text as body, false as has_spoilers', [ActivityType::FinishedStory->value]);

        $rated = DB::table('ratings as r')
            ->whereIn('r.user_id', $actors)
            ->whereExists($publicLibrary('r.user_id'))
            ->whereExists($visibleMedia('r.media_id'))
            ->selectRaw('r.updated_at as at, ?::smallint as kind, r.user_id as actor_id, r.media_id as ref_id, r.media_id as media_id, null::text as body, false as has_spoilers', [ActivityType::RatedStory->value]);

        $reviewed = DB::table('reviews as v')
            ->whereIn('v.user_id', $actors)
            ->whereNull('v.deleted_at')->where('v.status', ReviewState::Published->value)
            ->whereExists($visibleMedia('v.media_id'))
            ->selectRaw('v.created_at as at, ?::smallint as kind, v.user_id as actor_id, v.id as ref_id, v.media_id as media_id, case when v.has_spoilers then null else v.body end as body, v.has_spoilers as has_spoilers', [ActivityType::PublishedReview->value]);

        $collected = DB::table('collections as c')
            ->whereIn('c.user_id', $actors)
            ->where('c.visibility', Visibility::Public->value)->where('c.is_editorial', false)->whereNull('c.moderation_hidden_at')
            ->selectRaw('c.created_at as at, ?::smallint as kind, c.user_id as actor_id, c.id as ref_id, null::bigint as media_id, null::text as body, false as has_spoilers', [ActivityType::MadeCollection->value]);

        return $finished->unionAll($rated)->unionAll($reviewed)->unionAll($collected);
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return Collection<int, ActivityEvent>
     */
    private static function hydrate(User $viewer, Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return new Collection;
        }

        $users = User::query()->with('profile:id,user_id,handle,avatar_path')
            ->whereIn('id', $rows->pluck('actor_id')->unique()->all())->get(['id', 'name'])->keyBy('id');

        $media = Media::query()->unmerged()->visibleTo($viewer)
            ->whereIn('media.id', $rows->pluck('media_id')->filter()->unique()->all())
            ->get(['media.id', 'media.slug', 'media.title', 'media.cover_url', 'media.cover_path'])->keyBy('id');

        $collections = ReaderCollection::query()
            ->whereIn('id', $rows->where('kind', ActivityType::MadeCollection->value)->pluck('ref_id')->all())
            ->get(['id', 'slug', 'name'])->keyBy('id');
        $covers = self::covers($viewer, array_values($collections->keys()->all()));

        return $rows->map(function (stdClass $row) use ($users, $media, $collections, $covers): ?ActivityEvent {
            $type = ActivityType::from((int) $row->kind);
            $user = $users->get($row->actor_id);

            if ($user === null) {
                return null;
            }

            $actor = ['id' => $user->id, 'name' => $user->name, 'handle' => $user->profile?->handle, 'avatar_path' => $user->profile?->avatar_path];
            $at = Carbon::parse($row->at);

            if ($type === ActivityType::MadeCollection) {
                $collection = $collections->get($row->ref_id);

                return $collection === null ? null : new ActivityEvent($type, $actor, null, ['id' => $collection->id, 'slug' => $collection->slug, 'name' => $collection->name, 'cover' => $covers[$collection->id] ?? null], $at);
            }

            $story = $media->get($row->media_id);

            if ($story === null) {
                return null;
            }

            $spoilers = (bool) $row->has_spoilers;

            return new ActivityEvent(
                $type, $actor, ['id' => $story->id, 'slug' => $story->slug, 'title' => $story->title, 'cover' => $story->cover_url], null, $at,
                $type === ActivityType::PublishedReview && ! $spoilers && $row->body !== null ? self::excerpt((string) $row->body) : null,
                $type === ActivityType::PublishedReview && $spoilers,
            );
        })->filter()->values();
    }

    /**
     * The cover of the first story in each collection that the viewer may see.
     *
     * @param  list<int>  $ids
     * @return array<int, string|null>
     */
    private static function covers(User $viewer, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $items = DB::table('collection_items as i')
            ->joinSub(Media::query()->unmerged()->visibleTo($viewer)->select('media.id', 'media.cover_url')->toBase(), 'm', 'm.id', '=', 'i.media_id')
            ->whereIn('i.collection_id', $ids)
            ->orderBy('i.collection_id')->orderBy('i.position')->orderBy('i.id')
            ->get(['i.collection_id', 'm.cover_url']);

        return $items->unique('collection_id')->pluck('cover_url', 'collection_id')->all();
    }

    /**
     * A short plain-text excerpt of a review: its Markdown rendered safely, stripped of tags and collapsed, at most 200 characters.
     */
    private static function excerpt(string $body): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) Markdown::safe($body)), ENT_QUOTES | ENT_HTML5)));

        return mb_strlen($text) > self::EXCERPT_LENGTH ? rtrim(mb_substr($text, 0, self::EXCERPT_LENGTH - 1)).'…' : $text;
    }

    private static function encode(stdClass $row): string
    {
        return rtrim(strtr(base64_encode((string) json_encode([(string) $row->at, (int) $row->kind, (int) $row->actor_id, (int) $row->ref_id])), '+/', '-_'), '=');
    }

    /**
     * @return array{0: string, 1: int, 2: int, 3: int}|null null for a missing or malformed cursor, which starts from the newest
     */
    private static function decode(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $values = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);

        if (! is_array($values) || count($values) !== 4 || ! is_string($values[0]) || ! is_int($values[1]) || ! is_int($values[2]) || ! is_int($values[3])
            || strtotime($values[0]) === false) {
            return null;
        }

        return [$values[0], $values[1], $values[2], $values[3]];
    }
}
