<?php

namespace App\Discovery;

use App\Enums\ReadingStatus;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Query\JoinClause;

/**
 * The first shelf of Home: what the reader is in the middle of, the one they read last first. It is the reader's own entries at Reading, so
 * nothing is shared between readers or cached, and it goes through the mature gate like every list of stories (a story the reader has not
 * opted in to stays in the library but is not offered here). Each story carries `progress_percent`, `progress_chapter` and `progress_page` for the tile.
 */
class ContinueReading
{
    /**
     * Null when there is nothing to continue.
     */
    public function shelf(User $user, int $limit = 20): ?Shelf
    {
        $entries = LibraryEntry::query()
            ->where('user_library.user_id', $user->id)
            ->where('user_library.status', ReadingStatus::Reading->value)
            ->visibleTo($user)
            ->leftJoin('reading_progress', fn (JoinClause $join) => $join->on('reading_progress.user_id', '=', 'user_library.user_id')->on('reading_progress.media_id', '=', 'user_library.media_id'))
            ->select('user_library.*', 'reading_progress.percent as progress_percent', 'reading_progress.chapter as progress_chapter', 'reading_progress.page as progress_page')
            ->orderByRaw('reading_progress.last_read_at desc nulls last')
            ->orderByDesc('user_library.updated_at')->orderByDesc('user_library.id')
            ->limit($limit)
            ->with(['media' => fn ($media) => $media->with(['mediaType', 'publicPrimaryDewey.deweyClass'])])
            ->get();

        if ($entries->isEmpty()) {
            return null;
        }

        $media = $entries->map(function (LibraryEntry $entry): Media {
            return $entry->media->setAttribute('progress_percent', $entry->getAttribute('progress_percent'))
                ->setAttribute('progress_chapter', $entry->getAttribute('progress_chapter'))
                ->setAttribute('progress_page', $entry->getAttribute('progress_page'));
        });

        return new Shelf('continue-reading', __('Continue Reading'), $media->values(), description: __('Pick up where you left off, the story you read last first.'));
    }
}
