<?php

namespace App\Models;

use App\Enums\DuplicateStatus;
use Database\Factories\DuplicateCandidateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $media_id
 * @property int $duplicate_media_id
 * @property float $score
 * @property DuplicateStatus $status
 * @property int|null $resolved_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['media_id', 'duplicate_media_id', 'score', 'status', 'resolved_by'])]
class DuplicateCandidate extends Model
{
    /** @use HasFactory<DuplicateCandidateFactory> */
    use HasFactory;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'float',
            'status' => DuplicateStatus::class,
        ];
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
    public function duplicateMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'duplicate_media_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
