<?php

namespace App\Actions\Library;

use App\Discovery\Recommendations\InteractionRecorder;
use App\Enums\InteractionEvent;
use App\Enums\ReadingStatus;
use App\Events\LibraryChanged;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\User;

/**
 * Move a story to another status, adding it first when it is not in the library. Entering Reading stamps `started_at` and entering Completed
 * stamps `completed_at`, each only the first time. Setting Completed here records no completion: that is LogProgress's rule, so a story
 * is never counted as finished twice.
 */
class SetLibraryStatus
{
    public function __construct(
        private readonly AddToLibrary $add,
        private readonly InteractionRecorder $interactions,
    ) {}

    public function handle(User $user, Media $media, ReadingStatus $status): LibraryEntry
    {
        $entry = LibraryEntry::where('user_id', $user->id)->where('media_id', $media->id)->first();

        if ($entry === null) {
            $entry = $this->add->handle($user, $media, $status);
            $status === ReadingStatus::Abandoned && $this->interactions->record($user, InteractionEvent::Abandon, $media);

            return $entry;
        }

        $moved = $entry->status !== $status;

        $entry->update([
            'status' => $status,
            'started_at' => $status === ReadingStatus::Reading ? $entry->started_at ?? now() : $entry->started_at,
            'completed_at' => $status === ReadingStatus::Completed ? $entry->completed_at ?? now() : $entry->completed_at,
        ]);

        if ($moved) {
            $status === ReadingStatus::Abandoned && $this->interactions->record($user, InteractionEvent::Abandon, $media);
            LibraryChanged::dispatch($user, $media);
        }

        return $entry;
    }
}
