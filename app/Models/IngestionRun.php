<?php

namespace App\Models;

use App\Enums\IngestionMode;
use App\Enums\IngestionStatus;
use Carbon\CarbonInterface;
use Database\Factories\IngestionRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $external_source_id
 * @property IngestionMode $mode
 * @property IngestionStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property array{fetched: int, created: int, updated: int, merged: int, skipped: int, failed: int, failed_ids?: list<array{id: string, error: string}>} $counts
 * @property int $pages
 * @property string|null $page_token The next page to fetch, so a retry or the next run resumes there.
 * @property Carbon|null $max_updated_at The newest provider-side update seen so far; reported only, the cursor never follows it.
 * @property Carbon|null $walk_started_at When the walk this run belongs to began; a run that resumes a cut-short one inherits it. Null means this run's own start.
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['external_source_id', 'mode', 'status', 'started_at', 'finished_at', 'counts', 'pages', 'page_token', 'max_updated_at', 'walk_started_at', 'error'])]
class IngestionRun extends Model
{
    /** @use HasFactory<IngestionRunFactory> */
    use HasFactory;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'running',
        'counts' => '{"fetched":0,"created":0,"updated":0,"merged":0,"skipped":0,"failed":0,"failed_ids":[]}',
        'pages' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => IngestionMode::class,
            'status' => IngestionStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'max_updated_at' => 'datetime',
            'walk_started_at' => 'datetime',
            'counts' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ExternalSource, $this>
     */
    public function externalSource(): BelongsTo
    {
        return $this->belongsTo(ExternalSource::class);
    }

    /**
     * The most failed external ids kept per run, so a run that fails everything stays small.
     */
    public const MAX_FAILED_IDS = 100;

    /**
     * Fold one finished page into the run in one write, and remember where to go next.
     *
     * @param  array{fetched: int, created: int, updated: int, merged: int, skipped: int, failed: int}  $counts
     * @param  list<array{id: string, error: string}>  $failedIds
     */
    public function recordPage(array $counts, array $failedIds, ?string $nextPageToken, ?CarbonInterface $maxUpdatedAt): void
    {
        DB::transaction(function () use ($counts, $failedIds, $nextPageToken, $maxUpdatedAt): void {
            $run = static::query()->lockForUpdate()->findOrFail($this->id);
            $total = $run->counts;

            foreach ($counts as $key => $amount) {
                $total[$key] += $amount;
            }

            $total['failed_ids'] = array_slice([...($total['failed_ids'] ?? []), ...$failedIds], 0, self::MAX_FAILED_IDS);

            $run->update([
                'counts' => $total,
                'pages' => $run->pages + 1,
                'page_token' => $nextPageToken,
                'max_updated_at' => $maxUpdatedAt && (! $run->max_updated_at || $maxUpdatedAt->gt($run->max_updated_at)) ? $maxUpdatedAt : $run->max_updated_at,
            ]);
        });

        $this->refresh();
    }

    /**
     * Close the run once its pages are done: all fine is succeeded, some failed is partial, all failed is failed.
     * Record failures never freeze the sync: the cursor still moves on, and the failed ids stay in the counts.
     * The cursor is the start of the walk minus `sync.cursor_overlap_minutes`, never a provider timestamp, so a record updated
     * while the walk ran is read again next time. A run cut short by `max_pages_per_run` leaves its next page as the resume
     * point instead and the cursor stays until the whole walk completes.
     */
    public function finish(): void
    {
        $failed = $this->counts['failed'];
        $total = $this->counts['fetched'];

        $this->update([
            'status' => match (true) {
                $failed === 0 => IngestionStatus::Succeeded,
                $failed >= $total => IngestionStatus::Failed,
                default => IngestionStatus::Partial,
            },
            'finished_at' => now(),
            'error' => $failed > 0 ? "{$failed} of {$total} records failed to import." : null,
        ]);

        $this->externalSource->update([
            'sync_cursor' => $this->page_token !== null
                ? $this->resumeCursor()
                : ['since' => $this->walkStartedAt()->subMinutes((int) config('bookflix.sync.cursor_overlap_minutes'))->toIso8601String()],
            'last_synced_at' => now(),
        ]);
    }

    /**
     * Close a run that is still running as failed, whatever went wrong, and keep its page as the resume point.
     * A run that already finished is left as it is.
     */
    public function fail(string $error): void
    {
        $closed = static::query()->whereKey($this->id)->where('status', IngestionStatus::Running)
            ->update(['status' => IngestionStatus::Failed, 'finished_at' => now(), 'error' => Str::limit($error, 1000, '...')]);

        if ($closed > 0 && $this->refresh()->page_token !== null) {
            $this->externalSource->update(['sync_cursor' => $this->resumeCursor()]);
        }
    }

    /**
     * The source cursor with this run's resume point: the finished cursor is kept, and the next run starts at the stored page.
     *
     * @return array{since: mixed, resume: array{mode: string, page_token: string|null, max_updated_at: string|null, walk_started_at: string}}
     */
    private function resumeCursor(): array
    {
        return [
            'since' => $this->externalSource->sync_cursor['since'] ?? null,
            'resume' => ['mode' => $this->mode->value, 'page_token' => $this->page_token, 'max_updated_at' => $this->max_updated_at?->toIso8601String(), 'walk_started_at' => $this->walkStartedAt()->toIso8601String()],
        ];
    }

    private function walkStartedAt(): CarbonInterface
    {
        return $this->walk_started_at ?? $this->started_at;
    }
}
