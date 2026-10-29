<?php

namespace App\Jobs;

use App\Library\ExportStorage;
use App\Models\User;
use App\Notifications\DataExportReady;
use Generator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Write one reader's data to a zip and mail them the link (spec section 60). The zip holds the reader's own rows and nothing else: every query
 * is scoped to `userId`, and nothing is filtered by the mature gate, since a hidden entry is still theirs. It is built in a scratch folder, one
 * JSON file at a time and a few hundred rows at a time, so a long history never sits in memory, then stored on the private export disk as
 * `exports/{userId}/{ulid}.zip` (see ExportStorage). A reader keeps one export: the new file replaces the older ones. A reader who no longer
 * exists, is deactivated or has not verified their email gets nothing: the link goes to that address.
 */
class ExportUserData implements ShouldQueue
{
    use Queueable;

    /** Rows read from the database at a time. */
    private const CHUNK = 500;

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public readonly int $userId) {}

    public function handle(): void
    {
        $user = User::query()->active()->whereNotNull('email_verified_at')->find($this->userId);

        if ($user === null) {
            return;
        }

        $storage = app(ExportStorage::class);
        $disk = $storage->disk();
        $file = (string) Str::ulid();
        $work = sys_get_temp_dir().'/bookflix-export-'.Str::random(24);
        File::makeDirectory($work, 0700, true);

        try {
            $this->write($work.'/profile.json', $this->profile($user));

            foreach ([
                'preferences.json' => $this->preferences($user),
                'library.json' => $this->library($user),
                'collections.json' => $this->collections($user),
                'ratings.json' => $this->ratings($user),
                'progress.json' => $this->progress($user),
                'history.json' => $this->history($user),
                'interactions.json' => $this->interactions($user),
            ] as $name => $rows) {
                $this->writeList("{$work}/{$name}", $rows);
            }

            $zip = new ZipArchive;

            if ($zip->open($work.'/export.zip', ZipArchive::CREATE) !== true) {
                throw new RuntimeException('The export archive could not be created.');
            }

            foreach (['profile.json', 'preferences.json', 'library.json', 'collections.json', 'ratings.json', 'progress.json', 'history.json', 'interactions.json'] as $name) {
                $zip->addFile("{$work}/{$name}", $name);
            }

            $zip->close();

            $stream = fopen($work.'/export.zip', 'r') ?: throw new RuntimeException('The export archive could not be read back.');
            $disk->put($storage->path($user->id, $file), $stream, ['visibility' => 'private']);
            fclose($stream);
        } finally {
            File::deleteDirectory($work);
        }

        $storage->clear($user->id, keep: $file);

        $user->notify(new DataExportReady($file));
    }

    /**
     * The account and its profile, field by field: nothing that signs in (the password, two-factor secrets, the remember token) and no storage paths.
     *
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        $profile = $user->profile;

        return [
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $this->time($user->email_verified_at),
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                'created_at' => $this->time($user->created_at),
                'updated_at' => $this->time($user->updated_at),
            ],
            'profile' => [
                'bio' => $profile->bio,
                'has_avatar' => filled($profile->avatar_path),
                'preferred_languages' => $profile->preferred_languages,
                'reading_length' => $profile->reading_length->value,
                'profile_visibility' => $profile->profile_visibility->value,
                'library_visibility' => $profile->library_visibility->value,
                'personalization_enabled' => $profile->personalization_enabled,
                'show_mature' => $profile->show_mature,
                'adult_confirmed_at' => $this->time($profile->adult_confirmed_at),
                'onboarded_at' => $this->time($profile->onboarded_at),
                'created_at' => $this->time($profile->created_at),
                'updated_at' => $this->time($profile->updated_at),
            ],
        ];
    }

    /**
     * The reader's saved tastes. The table arrives with onboarding (Phase 5.8), so until it exists the file is an empty list. 5.8 replaces the raw
     * rows with labelled ones (the genre, theme or type a row points at) here, in this method.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function preferences(User $user): Generator
    {
        if (! Schema::hasTable('user_preferences')) {
            return;
        }

        foreach (DB::table('user_preferences')->where('user_id', $user->id)->cursor() as $row) {
            yield Arr::except((array) $row, ['user_id']);
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function library(User $user): Generator
    {
        $rows = DB::table('user_library')
            ->join('media', 'media.id', '=', 'user_library.media_id')
            ->where('user_library.user_id', $user->id)
            ->select('user_library.id', 'user_library.media_id', 'media.title', 'media.slug', 'user_library.status', 'user_library.favorited_at', 'user_library.started_at', 'user_library.completed_at', 'user_library.created_at', 'user_library.updated_at')
            ->lazyById(self::CHUNK, 'user_library.id', 'id');

        foreach ($rows as $row) {
            yield [
                'media_id' => $row->media_id,
                'title' => $row->title,
                'slug' => $row->slug,
                'status' => $row->status,
                'favorite' => $row->favorited_at !== null,
                'favorited_at' => $this->time($row->favorited_at),
                'started_at' => $this->time($row->started_at),
                'completed_at' => $this->time($row->completed_at),
                'added_at' => $this->time($row->created_at),
                'updated_at' => $this->time($row->updated_at),
            ];
        }
    }

    /**
     * Each collection with its stories in order.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function collections(User $user): Generator
    {
        foreach (DB::table('collections')->where('user_id', $user->id)->lazyById(100) as $collection) {
            $items = DB::table('collection_items')
                ->join('media', 'media.id', '=', 'collection_items.media_id')
                ->where('collection_items.collection_id', $collection->id)
                ->orderBy('collection_items.position')->orderBy('collection_items.id')
                ->select('collection_items.media_id', 'media.title', 'media.slug', 'collection_items.position', 'collection_items.note', 'collection_items.created_at')
                ->get()
                ->map(fn (object $item): array => [
                    'media_id' => $item->media_id,
                    'title' => $item->title,
                    'slug' => $item->slug,
                    'position' => $item->position,
                    'note' => $item->note,
                    'added_at' => $this->time($item->created_at),
                ])->all();

            yield [
                'name' => $collection->name,
                'slug' => $collection->slug,
                'description' => $collection->description,
                'visibility' => $collection->visibility,
                'is_editorial' => (bool) $collection->is_editorial,
                'created_at' => $this->time($collection->created_at),
                'updated_at' => $this->time($collection->updated_at),
                'items' => $items,
            ];
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function ratings(User $user): Generator
    {
        $rows = DB::table('ratings')
            ->join('media', 'media.id', '=', 'ratings.media_id')
            ->where('ratings.user_id', $user->id)
            ->select('ratings.media_id', 'media.title', 'media.slug', 'ratings.score', 'ratings.created_at', 'ratings.updated_at')
            ->lazyById(self::CHUNK, 'ratings.media_id', 'media_id');

        foreach ($rows as $row) {
            yield [
                'media_id' => $row->media_id,
                'title' => $row->title,
                'slug' => $row->slug,
                'score' => $row->score,
                'rated_at' => $this->time($row->created_at),
                'updated_at' => $this->time($row->updated_at),
            ];
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function progress(User $user): Generator
    {
        $rows = DB::table('reading_progress')
            ->join('media', 'media.id', '=', 'reading_progress.media_id')
            ->where('reading_progress.user_id', $user->id)
            ->select('reading_progress.media_id', 'media.title', 'media.slug', 'reading_progress.percent', 'reading_progress.page', 'reading_progress.chapter', 'reading_progress.locator', 'reading_progress.last_read_at')
            ->lazyById(self::CHUNK, 'reading_progress.media_id', 'media_id');

        foreach ($rows as $row) {
            yield [
                'media_id' => $row->media_id,
                'title' => $row->title,
                'slug' => $row->slug,
                'percent' => (float) $row->percent,
                'page' => $row->page,
                'chapter' => $row->chapter,
                'locator' => $row->locator === null ? null : json_decode($row->locator),
                'last_read_at' => $this->time($row->last_read_at),
            ];
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function history(User $user): Generator
    {
        $rows = DB::table('reading_history')
            ->join('media', 'media.id', '=', 'reading_history.media_id')
            ->where('reading_history.user_id', $user->id)
            ->select('reading_history.id', 'reading_history.media_id', 'media.title', 'media.slug', 'reading_history.started_at', 'reading_history.ended_at', 'reading_history.seconds', 'reading_history.from_percent', 'reading_history.to_percent', 'reading_history.chapters_completed')
            ->lazyById(self::CHUNK, 'reading_history.id', 'id');

        foreach ($rows as $row) {
            yield [
                'media_id' => $row->media_id,
                'title' => $row->title,
                'slug' => $row->slug,
                'started_at' => $this->time($row->started_at),
                'ended_at' => $this->time($row->ended_at),
                'seconds' => $row->seconds,
                'from_percent' => (float) $row->from_percent,
                'to_percent' => (float) $row->to_percent,
                'chapters_completed' => $row->chapters_completed,
            ];
        }
    }

    /**
     * The signals recorded for the recommender (a story that was since removed has no title).
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function interactions(User $user): Generator
    {
        $rows = DB::table('user_interactions')
            ->leftJoin('media', 'media.id', '=', 'user_interactions.media_id')
            ->where('user_interactions.user_id', $user->id)
            ->select('user_interactions.id', 'user_interactions.event', 'user_interactions.media_id', 'media.title', 'user_interactions.value', 'user_interactions.context', 'user_interactions.occurred_at')
            ->lazyById(self::CHUNK, 'user_interactions.id', 'id');

        foreach ($rows as $row) {
            yield [
                'event' => $row->event,
                'media_id' => $row->media_id,
                'title' => $row->title,
                'value' => $row->value === null ? null : (float) $row->value,
                'context' => json_decode($row->context ?? '{}'),
                'occurred_at' => $this->time($row->occurred_at),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(string $path, array $data): void
    {
        File::put($path, json_encode($data, self::JSON | JSON_PRETTY_PRINT));
    }

    /**
     * Write a JSON array one row per line, as the rows arrive.
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    private function writeList(string $path, iterable $rows): void
    {
        $handle = fopen($path, 'w') ?: throw new RuntimeException('The export could not be written.');
        fwrite($handle, '[');
        $first = true;

        foreach ($rows as $row) {
            fwrite($handle, ($first ? "\n" : ",\n").json_encode($row, self::JSON));
            $first = false;
        }

        fwrite($handle, $first ? ']' : "\n]");
        fclose($handle);
    }

    private function time(Carbon|string|null $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }
}
