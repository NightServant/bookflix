<?php

namespace App\Models;

use App\Enums\ReasonType;
use Database\Factories\RecommendationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A title shown to a reader, with the score, the reason and the ranker's feature values at that moment. Kept for explanations and evaluation, and
 * never written for a reader who opted out of personalization.
 *
 * @property int $id
 * @property int $user_id
 * @property int $media_id
 * @property string $shelf
 * @property float $score
 * @property ReasonType $reason_type
 * @property int|null $reason_media_id
 * @property array<string, mixed> $reason_payload
 * @property int $model_version_id
 * @property Carbon $generated_at
 */
#[Fillable(['user_id', 'media_id', 'shelf', 'score', 'reason_type', 'reason_media_id', 'reason_payload', 'model_version_id', 'generated_at'])]
class Recommendation extends Model
{
    /** @use HasFactory<RecommendationFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'float',
            'reason_type' => ReasonType::class,
            'reason_payload' => 'array',
            'generated_at' => 'datetime',
        ];
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

    /**
     * @return BelongsTo<Media, $this>
     */
    public function reasonMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'reason_media_id');
    }

    /**
     * @return BelongsTo<ModelVersion, $this>
     */
    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }

    /**
     * @return HasMany<RecommendationEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(RecommendationEvent::class);
    }
}
