<?php

namespace App\Models;

use App\Enums\ClassificationMethod;
use App\Enums\ClassificationType;
use App\Enums\ReviewStatus;
use Database\Factories\MediaDeweyClassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $media_id
 * @property int $dewey_class_id
 * @property ClassificationType $classification_type
 * @property float $confidence
 * @property string $source admin | open_library | rules | dewey-classifier
 * @property ClassificationMethod $classification_method
 * @property int|null $model_version_id
 * @property ReviewStatus $review_status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'media_id',
    'dewey_class_id',
    'classification_type',
    'confidence',
    'source',
    'classification_method',
    'model_version_id',
    'review_status',
    'reviewed_by',
    'reviewed_at',
])]
class MediaDeweyClass extends Model
{
    /** @use HasFactory<MediaDeweyClassFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'classification_type' => ClassificationType::class,
            'classification_method' => ClassificationMethod::class,
            'review_status' => ReviewStatus::class,
            'confidence' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Rows the public site may show: accepted, or pending with enough confidence. Rejected rows never show.
     *
     * @param  Builder<MediaDeweyClass>  $query
     */
    public function scopePublic(Builder $query): void
    {
        $query->where(fn (Builder $inner) => $inner
            ->where('review_status', ReviewStatus::Accepted)
            ->orWhere(fn (Builder $pending) => $pending
                ->where('review_status', ReviewStatus::Pending)
                ->where('confidence', '>=', config('bookflix.classification.public_min_confidence'))));
    }

    /**
     * A public row that is also the media's primary class: the only kind that places a story on a shelf or in a count.
     *
     * @param  Builder<MediaDeweyClass>  $query
     */
    public function scopePublicPrimary(Builder $query): void
    {
        $query->public()->where('classification_type', ClassificationType::Primary);
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /**
     * @return BelongsTo<DeweyClass, $this>
     */
    public function deweyClass(): BelongsTo
    {
        return $this->belongsTo(DeweyClass::class);
    }
}
