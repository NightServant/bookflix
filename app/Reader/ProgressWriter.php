<?php

namespace App\Reader;

use App\Actions\Library\LogProgress;
use App\Models\ContentSource;
use App\Models\ReadingProgress;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * What a reader's browser saves while it reads (P9-R13). The payload is checked closely, since it comes from a page the reader controls: a locator of
 * known keys only, a percent from 0 to 100, a chapter name, the seconds read since the last save, a random id for the page load and a save number that only
 * goes up within it. LogProgress orders by that pair under its lock (a late retry from the same page load is dropped; another tab or device wins by arrival), and keeps one
 * history row and one signal per reading session. The caller has already asked the access gate.
 */
class ProgressWriter
{
    /** The keys of a locator, each one the way one engine says where it is: an EPUB CFI, a section index with a fraction, a page. */
    private const LOCATOR_KEYS = ['cfi', 'index', 'fraction', 'page'];

    public function __construct(private readonly LogProgress $log) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return ReadingProgress the place now stored (unchanged when the save was a late retry)
     *
     * @throws ValidationException
     */
    public function write(User $user, ContentSource $source, array $payload): ReadingProgress
    {
        $data = Validator::make($payload, [
            'locator' => ['required', 'array', 'min:1', function (string $attribute, mixed $value, \Closure $fail): void {
                is_array($value) && array_diff_key($value, array_flip(self::LOCATOR_KEYS)) === [] || $fail(__('The locator has a key that is not allowed.'));
            }],
            'locator.cfi' => ['nullable', 'string', 'max:2048'],
            'locator.index' => ['nullable', 'integer', 'min:0'],
            'locator.fraction' => ['nullable', 'numeric', 'between:0,1'],
            'locator.page' => ['nullable', 'integer', 'min:1'],
            'percent' => ['required', 'numeric', 'between:0,100'],
            'chapter' => ['nullable', 'string', 'max:100'],
            'seconds' => ['nullable', 'integer', 'between:0,86400'],
            'client_session' => ['required', 'string', 'between:8,64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'client_seq' => ['required', 'integer', 'min:0'],
        ])->validate();

        // Keys the reader left out are not kept, so a CFI is never saved beside a page that belongs to another engine.
        $locator = array_filter($data['locator'], fn (mixed $value): bool => $value !== null);

        return $this->log->handle(
            $user,
            $source->media,
            (float) $data['percent'],
            page: isset($locator['page']) ? (int) $locator['page'] : null,
            chapter: $data['chapter'] ?? null,
            locator: $locator,
            seconds: (int) ($data['seconds'] ?? 0),
            source: $source,
            continueSession: true,
            clientSession: $data['client_session'],
            clientSeq: (int) $data['client_seq'],
        );
    }
}
