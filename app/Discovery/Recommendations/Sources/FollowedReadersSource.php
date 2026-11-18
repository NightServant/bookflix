<?php

namespace App\Discovery\Recommendations\Sources;

use App\Discovery\Recommendations\Candidate;
use App\Enums\ReadingStatus;
use App\Enums\ReasonType;
use App\Enums\Visibility;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stories the readers this reader follows finished or rated 8 or more (P10-R14). Only followed readers who are listed (active, non-admin, public
 * profile) and whose library is public count. The score is the number of distinct followers behind a story over the largest such number, and
 * the payload carries that number, never a name. It reads whom the reader follows, not their behavior, so an opted-out reader still gets it.
 */
class FollowedReadersSource extends Source
{
    /** The rating from which a followed reader's score counts as a recommendation. */
    public const MIN_SCORE = 8;

    public function key(): string
    {
        return 'followed_readers';
    }

    protected function gather(User $user, int $limit): Collection
    {
        $followers = $user->listedFollowing()
            ->whereExists(fn (Builder $profiles) => $profiles->selectRaw('1')->from('profiles')
                ->whereColumn('profiles.user_id', 'users.id')->where('profiles.library_visibility', Visibility::Public->value))
            ->select('users.id')->toBase();

        $liked = DB::table('user_library')->whereIn('user_id', $followers)->where('status', ReadingStatus::Completed->value)->select('user_id', 'media_id')
            ->union(DB::table('ratings')->whereIn('user_id', $followers)->where('score', '>=', self::MIN_SCORE)->select('user_id', 'media_id'));

        $rows = DB::query()->fromSub($liked, 'liked')
            ->joinSub($this->eligible($user)->select('media.id')->toBase(), 'eligible', 'eligible.id', '=', 'liked.media_id')
            ->groupBy('liked.media_id')
            ->orderByRaw('count(distinct liked.user_id) desc')->orderBy('liked.media_id')
            ->limit($limit)
            ->get(['liked.media_id', DB::raw('count(distinct liked.user_id) as followers')]);

        $most = (int) ($rows->first()->followers ?? 0);

        return $rows->map(fn (object $row): Candidate => new Candidate(
            (int) $row->media_id, $this->key(), $most > 0 ? (int) $row->followers / $most : 0.0, ReasonType::PopularWithFollowed, null, ['followers' => (int) $row->followers],
        ))->values();
    }
}
