<?php

namespace App\Models;

use App\Enums\RecommendationEventType;
use Database\Factories\RecommendationEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What happened to a shown recommendation: seen, clicked, dismissed or added to the library. A row is never changed.
 *
 * @property int $id
 * @property int $recommendation_id
 * @property RecommendationEventType $event
 * @property Carbon $occurred_at
 */
#[Fillable(['recommendation_id', 'event', 'occurred_at'])]
class RecommendationEvent extends Model
{
    /** @use HasFactory<RecommendationEventFactory> */
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
            'event' => RecommendationEventType::class,
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Recommendation, $this>
     */
    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class);
    }
}
