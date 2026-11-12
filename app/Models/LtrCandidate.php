<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One of a reader's top hybrid candidates with the ranker's features, exported nightly for the Python service to score. The features are what
 * `HybridRanker` computed; Python never recomputes one.
 *
 * @property int $user_id
 * @property int $media_id
 * @property array<string, float> $features
 * @property Carbon $generated_at
 */
#[Fillable(['user_id', 'media_id', 'features', 'generated_at'])]
class LtrCandidate extends Model
{
    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['features' => 'array', 'generated_at' => 'datetime'];
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
