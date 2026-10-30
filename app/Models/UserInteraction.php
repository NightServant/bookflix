<?php

namespace App\Models;

use App\Enums\InteractionEvent;
use Database\Factories\UserInteractionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One signal the recommender learns from. A row is never changed and holds no IP address or user agent; App\Discovery\Recommendations\InteractionRecorder
 * is the only writer, and writes nothing for a reader who opted out of personalization.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $media_id
 * @property InteractionEvent $event
 * @property float|null $value
 * @property array<string, mixed> $context
 * @property Carbon $occurred_at
 */
#[Fillable(['user_id', 'media_id', 'event', 'value', 'context', 'occurred_at'])]
class UserInteraction extends Model
{
    /** @use HasFactory<UserInteractionFactory> */
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
            'event' => InteractionEvent::class,
            'value' => 'float',
            'context' => 'array',
            'occurred_at' => 'datetime',
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
}
