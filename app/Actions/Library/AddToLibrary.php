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
 * Put a story in a reader's library. Idempotent: a story already there is returned as it is, whatever status is asked for, and nothing is
 * recorded twice. The caller decides who the reader is and whether they may see the story.
 */
class AddToLibrary
{
    public function __construct(private readonly InteractionRecorder $interactions) {}

    public function handle(User $user, Media $media, ReadingStatus $status = ReadingStatus::WantToRead): LibraryEntry
    {
        // createOrFirst, not firstOrCreate: two quick clicks race to the unique (user, media) key, and the loser should read the winner's row.
        $entry = LibraryEntry::createOrFirst(
            ['user_id' => $user->id, 'media_id' => $media->id],
            [
                'status' => $status,
                'started_at' => $status === ReadingStatus::Reading ? now() : null,
                'completed_at' => $status === ReadingStatus::Completed ? now() : null,
            ],
        );

        if ($entry->wasRecentlyCreated) {
            $this->interactions->record($user, InteractionEvent::LibraryAdd, $media);
            LibraryChanged::dispatch($user, $media);
        }

        return $entry;
    }
}
