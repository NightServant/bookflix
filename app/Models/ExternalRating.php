<?php

namespace App\Models;

use Database\Factories\ExternalRatingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A provider's own rating of a story on a 0 to 100 scale, one per media and source (P10-R20).
 *
 * @property int $media_id
 * @property string $source
 * @property int $score
 * @property int|null $votes
 * @property string|null $url
 * @property Carbon $fetched_at
 */
#[Fillable(['media_id', 'source', 'score', 'votes', 'url', 'fetched_at'])]
class ExternalRating extends Model
{
    /** @use HasFactory<ExternalRatingFactory> */
    use HasFactory;

    public $incrementing = false;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['score' => 'integer', 'votes' => 'integer', 'fetched_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
