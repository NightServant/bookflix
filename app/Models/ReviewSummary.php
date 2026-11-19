<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What readers say about a story: whole sentences taken from its published, non-spoiler reviews by the SummarizeReviews job. Shown (escaped) only
 * while it exists; no row means the story has fewer than the minimum number of qualifying reviews, or the summary is being rebuilt.
 *
 * @property int $media_id
 * @property string $summary
 * @property int $review_count
 * @property string $source_hash
 * @property Carbon $generated_at
 */
#[Fillable(['media_id', 'summary', 'review_count', 'source_hash', 'generated_at'])]
class ReviewSummary extends Model
{
    protected $primaryKey = 'media_id';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'review_count' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
