<?php

namespace App\Models;

use Database\Factories\MediaEmbeddingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The sentence embedding of one media under one `embedding` model version, written by the Python `embed-media` job. Only the active version's
 * rows are ever compared, so vectors of two models never meet in one query.
 *
 * @property int $media_id
 * @property int $model_version_id
 * @property list<float> $embedding
 * @property string $content_hash
 */
#[Fillable(['media_id', 'model_version_id', 'embedding', 'content_hash'])]
class MediaEmbedding extends Model
{
    /** @use HasFactory<MediaEmbeddingFactory> */
    use HasFactory;

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
        return ['embedding' => AsVector::class];
    }

    /**
     * A row is saved and deleted by both parts of its key.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query
            ->where('media_id', $this->getAttribute('media_id'))
            ->where('model_version_id', $this->getAttribute('model_version_id'));
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
