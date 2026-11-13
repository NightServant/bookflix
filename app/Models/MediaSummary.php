<?php

namespace App\Models;

use Database\Factories\MediaSummaryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The extractive summary of one media's description: whole sentences of it, in their original order, written by the Python `summarize-media`
 * job for live media whose description is over 600 characters. No row means the description is shown as it is.
 *
 * @property int $media_id
 * @property string $summary
 * @property string $source_hash
 * @property string $method
 * @property Carbon $generated_at
 */
#[Fillable(['media_id', 'summary', 'source_hash', 'method', 'generated_at'])]
class MediaSummary extends Model
{
    /** @use HasFactory<MediaSummaryFactory> */
    use HasFactory;

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
        return ['generated_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
