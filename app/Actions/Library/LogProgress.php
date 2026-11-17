<?php

namespace App\Actions\Library;

use App\Discovery\Recommendations\InteractionRecorder;
use App\Enums\InteractionEvent;
use App\Enums\ReadingStatus;
use App\Events\LibraryChanged;
use App\Models\ContentSource;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\ReadingProgress;
use App\Models\ReadingSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Log how far a reader has got in a story (by hand: the reader of Phase 9 will call this too). The place is saved over the last one, a session is
 * appended to the history, and the story is put in the library if it is not there. The caller decides who the reader is and whether they may see the story.
 *
 * The status follows the log. A story that was wanted, paused or abandoned becomes Reading (a log is the reader picking it up). Reaching 100
 * completes it, once: `completed_at` is set the first time only and `reading_complete` is recorded only while there is no `completed_at` and the
 * place logged before was below 100, so a re-read, a move back, or logging 100 again (also after the story was taken out of the library and put
 * back, which loses `completed_at` but not the place) counts nothing twice. A completed story stays completed when the reader logs a lower percent.
 * Signals: `reading_start` on the first log of a story that was not yet Reading (one already set to Reading by hand had its start stamped then),
 * `reading_progress` on every log with the distance moved (never negative), and `reading_complete`. An opted-out reader records none of them
 * (see InteractionRecorder) but keeps progress and history. Two logs of one story at once are serialized on the library row.
 *
 * The reader (Phase 9, P9-R13) passes the `$source` it is reading, which is remembered on the place, and `$continueSession`: a save within
 * `reader.session_gap_minutes` of the end of the last history row extends that row (more seconds, a new end and a new `to_percent`) instead of
 * appending one, and records no `reading_progress` signal, so one reading session is one row and one signal. Manual logs pass neither.
 */
class LogProgress
{
    /** The most pages a book may have in a log, and the most seconds one session may last (a day). */
    private const MAX_PAGE = 1_000_000;

    private const MAX_SECONDS = 86_400;

    public function __construct(
        private readonly AddToLibrary $add,
        private readonly InteractionRecorder $interactions,
    ) {}

    /**
     * @param  array<string, mixed>|null  $locator  where the reader is, in the reader's own terms (an EPUB CFI, a page index)
     *
     * @throws ValidationException when the percent is not a number from 0 to 100, or the page, chapter or time is out of range
     */
    public function handle(User $user, Media $media, float $percent, ?int $page = null, ?string $chapter = null, ?array $locator = null, int $seconds = 0, ?ContentSource $source = null, bool $continueSession = false): ReadingProgress
    {
        $this->validate($percent, $page, $chapter, $seconds);

        $to = round($percent, 2);
        $this->add->handle($user, $media);

        $events = [];

        $progress = DB::transaction(function () use ($user, $media, $to, $page, $chapter, $locator, $seconds, $source, $continueSession, &$events): ReadingProgress {
            $entry = LibraryEntry::where('user_id', $user->id)->where('media_id', $media->id)->lockForUpdate()->firstOrFail();
            $previous = ReadingProgress::where('user_id', $user->id)->where('media_id', $media->id)->first();
            $from = $previous === null ? 0.0 : (float) $previous->percent;

            $progress = $previous ?? new ReadingProgress(['user_id' => $user->id, 'media_id' => $media->id]);
            $progress->fill(['page' => $page, 'chapter' => $chapter, 'locator' => $locator, 'percent' => $to, 'last_read_at' => now()]);
            $source === null || $progress->content_source_id = $source->id;
            $progress->save();

            $session = $continueSession ? $this->openSession($user, $media) : null;

            if ($session === null) {
                ReadingSession::create([
                    'user_id' => $user->id,
                    'media_id' => $media->id,
                    'started_at' => now()->subSeconds($seconds),
                    'ended_at' => now(),
                    'seconds' => $seconds,
                    'from_percent' => $from,
                    'to_percent' => $to,
                ]);
            } else {
                $session->update(['ended_at' => now(), 'seconds' => min(self::MAX_SECONDS, $session->seconds + $seconds), 'to_percent' => $to]);
            }

            $picksUp = in_array($entry->status, [ReadingStatus::WantToRead, ReadingStatus::Paused, ReadingStatus::Abandoned], true);

            if ($previous === null && $picksUp) {
                $events[] = [InteractionEvent::ReadingStart, null];
            }

            // One `reading_progress` per session when the reader keeps saving; the distance of a session's later saves is not added up.
            $session === null && $events[] = [InteractionEvent::ReadingProgress, round(max(0.0, $to - $from), 2)];

            if ($to >= 100.0) {
                // Not for a reader already at 100: the entry may be new (the story was taken out of the library and put back), but the story was finished before.
                $entry->completed_at === null && $from < 100.0 && $events[] = [InteractionEvent::ReadingComplete, null];

                $entry->update(['status' => ReadingStatus::Completed, 'started_at' => $entry->started_at ?? now(), 'completed_at' => $entry->completed_at ?? now()]);
            } elseif ($picksUp) {
                $entry->update(['status' => ReadingStatus::Reading, 'started_at' => $entry->started_at ?? now()]);
            }

            return $progress;
        });

        foreach ($events as [$event, $value]) {
            $this->interactions->record($user, $event, $media, value: $value);
        }

        LibraryChanged::dispatch($user, $media);

        return $progress;
    }

    /**
     * The last history row of this reader and story, when it ended within the session gap.
     */
    private function openSession(User $user, Media $media): ?ReadingSession
    {
        return ReadingSession::where('user_id', $user->id)->where('media_id', $media->id)
            ->where('ended_at', '>=', now()->subMinutes((int) config('bookflix.reader.session_gap_minutes')))
            ->latest('ended_at')->latest('id')->first();
    }

    private function validate(float $percent, ?int $page, ?string $chapter, int $seconds): void
    {
        $errors = [];

        if (! is_finite($percent) || $percent < 0 || $percent > 100) {
            $errors['percent'] = __('Progress must be a number from 0 to 100.');
        }

        if ($page !== null && ($page < 1 || $page > self::MAX_PAGE)) {
            $errors['page'] = __('The page must be a whole number of at least 1.');
        }

        if ($chapter !== null && mb_strlen($chapter) > 100) {
            $errors['chapter'] = __('The chapter may be at most 100 characters.');
        }

        if ($seconds < 0 || $seconds > self::MAX_SECONDS) {
            $errors['seconds'] = __('The time must be between 0 seconds and a day.');
        }

        $errors === [] || throw ValidationException::withMessages($errors);
    }
}
