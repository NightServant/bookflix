<?php

namespace App\Reader;

use App\Enums\AccessType;
use App\Enums\ContentFormat;
use App\Enums\ContentStatus;
use App\Jobs\ConvertCbrToCbz;
use App\Models\ContentSource;
use App\Models\Media;
use App\Models\User;
use App\Reader\Archives\EpubInspector;
use App\Reader\Archives\UnsafeArchive;
use App\Reader\Cbz\CbrConverter;
use App\Reader\Cbz\CbzExtractor;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Takes a reader's own book (P9-R12). Nothing the client says is believed: the type comes from the bytes (PDF magic with finfo, a zip that passes
 * the EPUB rules or the comic rules, the RAR signature), the size caps and the per-reader quota come from config, and the file is stored
 * streaming under a server-made name, so the client name never reaches a path. Every attempt counts against the hourly limit, refused or not,
 * and is counted before the file is read. A CBR is saved pending and converted in the background.
 */
class UploadContentSource
{
    private const RAR_SIGNATURES = ["Rar!\x1A\x07\x00", "Rar!\x1A\x07\x01\x00"];

    public function __construct(
        private readonly EpubInspector $epubs,
        private readonly CbzExtractor $comics,
        private readonly CbrConverter $converter,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $user, Media $media, UploadedFile $file): ContentSource
    {
        if ($user->isDeactivated() || Gate::forUser($user)->denies('use-reader-side')) {
            throw ValidationException::withMessages(['user' => __('Only reader accounts can add files.')]);
        }

        if ($media->trashed() || ! Media::query()->visibleTo($user)->whereKey($media->id)->exists()) {
            throw ValidationException::withMessages(['media' => __('Choose a story from the catalog.')]);
        }

        $this->countAttempt($user);

        $path = (string) $file->getRealPath();
        $size = (int) filesize($path);

        if ($size === 0) {
            throw $this->refuse(__('This file is empty.'));
        }

        $format = $this->detect($path);

        $capMb = (float) config('bookflix.reader.upload.'.($format === ContentFormat::Cbr ? 'cbz' : $format->value).'_mb');

        if ($size > $capMb * 1048576) {
            throw $this->refuse(__('This file is larger than :size MB.', ['size' => $capMb]));
        }

        $quotaMb = (float) config('bookflix.reader.upload.quota_mb');
        $used = (int) ContentSource::query()->where('access_type', AccessType::UserOwned)->where('owner_user_id', $user->id)->sum('byte_size');

        if ($used + $size > $quotaMb * 1048576) {
            throw $this->refuse(__('This file would take you over your :size of storage.', ['size' => $quotaMb >= 1024 ? rtrim(rtrim(number_format($quotaMb / 1024, 1), '0'), '.').' GB' : $quotaMb.' MB']));
        }

        $disk = Storage::disk(ContentSource::disk());
        $stored = "content/{$user->id}/".Str::ulid().'.'.$format->value;

        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw $this->refuse(__('This file could not be read.'));
        }

        try {
            $disk->writeStream($stored, $stream);
        } finally {
            fclose($stream);
        }

        try {
            $source = DB::transaction(fn (): ContentSource => ContentSource::create([
                'media_id' => $media->id,
                'access_type' => AccessType::UserOwned,
                'owner_user_id' => $user->id,
                'format' => $format,
                'storage_path' => $stored,
                'license' => 'user-provided',
                'verified_at' => now(),
                'verified_by' => null,
                'byte_size' => $size,
                'checksum' => (string) hash_file('sha256', $path),
                'mime_type' => $format->mimeType(),
                'original_name' => $this->displayName($file->getClientOriginalName()),
                'status' => $format === ContentFormat::Cbr ? ContentStatus::Pending : ContentStatus::Ready,
            ]));
        } catch (Throwable $e) {
            $disk->delete($stored);

            throw $e;
        }

        if ($format === ContentFormat::Cbr) {
            ConvertCbrToCbz::dispatch($source->id)->afterCommit();
        }

        return $source;
    }

    private function countAttempt(User $user): void
    {
        $key = 'reader-upload:'.$user->id;
        $max = (int) config('bookflix.reader.upload.per_hour');

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw $this->refuse(__("You've added :count files this hour. Try again later.", ['count' => $max]));
        }

        RateLimiter::hit($key, 3600);
    }

    /**
     * What the file really is, judged from its content alone.
     *
     * @throws ValidationException
     */
    private function detect(string $path): ContentFormat
    {
        $handle = fopen($path, 'rb');
        $head = $handle === false ? '' : (string) fread($handle, 8);

        if ($handle !== false) {
            fclose($handle);
        }

        if (str_starts_with($head, '%PDF-') && (new finfo(FILEINFO_MIME_TYPE))->file($path) === 'application/pdf') {
            return ContentFormat::Pdf;
        }

        if (str_starts_with($head, "PK\x03\x04")) {
            return $this->detectZip($path);
        }

        foreach (self::RAR_SIGNATURES as $signature) {
            if (str_starts_with($head, $signature)) {
                if (! $this->converter->available()) {
                    throw $this->refuse(CbrConverter::UNAVAILABLE_MESSAGE);
                }

                return ContentFormat::Cbr;
            }
        }

        throw $this->refuse(__('This file is not an EPUB, PDF, CBZ or CBR.'));
    }

    /**
     * @throws ValidationException
     */
    private function detectZip(string $path): ContentFormat
    {
        try {
            $this->epubs->check($path);

            return ContentFormat::Epub;
        } catch (UnsafeArchive) {
            // Not an EPUB (or not safe): the comic rules decide next, and their message is the one a hostile archive gets.
        }

        try {
            $this->comics->indexFile($path);

            return ContentFormat::Cbz;
        } catch (UnsafeArchive $e) {
            if ($e->hostile) {
                throw $this->refuse($e->getMessage());
            }

            throw $this->refuse(__('This file is not an EPUB, PDF, CBZ or CBR.'));
        }
    }

    /**
     * The client name kept only to show: the last path part, control characters removed, at most 255 characters.
     */
    private function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $name);

        return mb_substr($name, 0, 255);
    }

    private function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['file' => $message]);
    }
}
