<?php

namespace App\Actions\Library;

use App\Discovery\Recommendations\InteractionRecorder;
use App\Enums\InteractionEvent;
use App\Events\LibraryChanged;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\User;

/**
 * Favorite a story, or take the favorite off. A favorite implies a library row (D-6), so favoriting a story that is not in the library adds it
 * as want to read; taking the favorite off leaves it there. Only turning it on is a signal.
 */
class ToggleFavorite
{
    public function __construct(
        private readonly AddToLibrary $add,
        private readonly InteractionRecorder $interactions,
    ) {}

    public function handle(User $user, Media $media): LibraryEntry
    {
        $entry = LibraryEntry::where('user_id', $user->id)->where('media_id', $media->id)->first()
            ?? $this->add->handle($user, $media);

        $favorite = $entry->favorited_at === null;
        $entry->update(['favorited_at' => $favorite ? now() : null]);

        $favorite && $this->interactions->record($user, InteractionEvent::Favorite, $media, value: 1.0);
        LibraryChanged::dispatch($user, $media);

        return $entry;
    }
}
