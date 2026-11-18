<?php

namespace App\Models;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A reader's report of a review, collection or profile (see App\Actions\Social for the only writers). The target is null once it has been deleted;
 * anything that shows a report treats that as "removed".
 *
 * @property int $id
 * @property int $reporter_id
 * @property string $reportable_type
 * @property int $reportable_id
 * @property ReportReason $reason
 * @property string|null $details
 * @property ReportStatus $status
 * @property int|null $handled_by
 * @property Carbon|null $handled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Review|Collection|Profile|null $reportable
 */
#[Fillable(['reporter_id', 'reason', 'details'])]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => ReportReason::class,
            'status' => ReportStatus::class,
            'handled_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
