<?php

namespace App\Models;

use App\Enums\IngestionMode;
use App\Enums\IngestionStatus;
use Database\Factories\IngestionRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $external_source_id
 * @property IngestionMode $mode
 * @property IngestionStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property array{fetched: int, created: int, updated: int, merged: int, skipped: int, failed: int} $counts
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['external_source_id', 'mode', 'status', 'started_at', 'finished_at', 'counts', 'error'])]
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
        'counts' => '{"fetched":0,"created":0,"updated":0,"merged":0,"skipped":0,"failed":0}',
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
}
