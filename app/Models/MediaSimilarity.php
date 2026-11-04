<?php

namespace App\Models;

use Database\Factories\MediaSimilarityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One neighbor of a media under one method (the top 50 per method), written by the Python service. The key is (media, method, model version, similar media), so a candidate version never overwrites the active one's pairs, and a
 * media is never its own neighbor.
 *
 * @property int $media_id
 * @property int $similar_media_id
 * @property string $method
 * @property float $score
 * @property int $model_version_id
 */
#[Fillable(['media_id', 'similar_media_id', 'method', 'score', 'model_version_id'])]
class MediaSimilarity extends Model
{
    /** @use HasFactory<MediaSimilarityFactory> */
    use HasFactory;

    protected $table = 'media_similarities';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['score' => 'float'];
    }

    /**
     * A row is saved and deleted by all four parts of its key.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query
            ->where('media_id', $this->getAttribute('media_id'))
            ->where('method', $this->getAttribute('method'))
            ->where('model_version_id', $this->getAttribute('model_version_id'))
            ->where('similar_media_id', $this->getAttribute('similar_media_id'));
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
    public function similar(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'similar_media_id');
    }

    /**
     * @return BelongsTo<ModelVersion, $this>
     */
    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }
}
