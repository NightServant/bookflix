<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The model-written summary of one media's description (P11-R5). Shown, labelled "AI-generated", only while its hash is the description's.
 *
 * @property int $media_id
 * @property string $summary
 * @property string $source_hash
 * @property int|null $model_version_id
 * @property Carbon $generated_at
 */
#[Fillable(['media_id', 'summary', 'source_hash', 'model_version_id', 'generated_at'])]
class MediaAiSummary extends Model
{
    protected $primaryKey = 'media_id';

    public $incrementing = false;

    public $timestamps = false;

    /**
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

    /**
     * @return BelongsTo<ModelVersion, $this>
     */
    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }
}
