<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What the active `ltr` version scored a reader's candidate at, written by the Python service's `score-ltr` job. One row per reader and title;
 * the page only reads them, and ignores rows older than 36 hours.
 *
 * @property int $user_id
 * @property int $media_id
 * @property float $score
 * @property int $model_version_id
 * @property Carbon $generated_at
 */
#[Fillable(['user_id', 'media_id', 'score', 'model_version_id', 'generated_at'])]
class LtrScore extends Model
{
    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['score' => 'float', 'generated_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
