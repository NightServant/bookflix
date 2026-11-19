<?php

namespace App\Models;

use Database\Factories\ExternalReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A review written on a provider's own site, kept as a short plain-text excerpt with a link back (P10-R20).
 *
 * @property int $id
 * @property int $media_id
 * @property string $source
 * @property string $external_id
 * @property string|null $author_name
 * @property int|null $rating 0 to 100
 * @property string $excerpt
 * @property string $url
 * @property Carbon|null $published_at
 * @property Carbon $fetched_at
 * @property Carbon|null $hidden_at
 * @property int|null $hidden_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['media_id', 'source', 'external_id', 'author_name', 'rating', 'excerpt', 'url', 'published_at', 'fetched_at', 'hidden_at', 'hidden_by'])]
class ExternalReview extends Model
{
    /** @use HasFactory<ExternalReviewFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'published_at' => 'datetime',
            'fetched_at' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<ExternalReview>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->whereNull('hidden_at');
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_by');
    }
}
