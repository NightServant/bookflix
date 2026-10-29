<?php

namespace App\Models;

use Database\Factories\MediaExternalIdFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $media_id
 * @property int $external_source_id
 * @property string $external_id
 * @property string|null $dewey_code The provider's own Dewey code for the media, normalized; read when classifying.
 * @property string|null $source_url
 * @property string $payload_hash
 * @property Carbon $imported_at
 * @property Carbon $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'media_id',
    'external_source_id',
    'external_id',
    'dewey_code',
    'source_url',
    'payload_hash',
    'imported_at',
    'last_synced_at',
])]
class MediaExternalId extends Model
{
    /** @use HasFactory<MediaExternalIdFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
            'last_synced_at' => 'datetime',
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
     * @return BelongsTo<ExternalSource, $this>
     */
    public function externalSource(): BelongsTo
    {
        return $this->belongsTo(ExternalSource::class);
    }
}
