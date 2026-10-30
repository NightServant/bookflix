<?php

namespace App\Models;

use App\Enums\CoverPolicy;
use Database\Factories\ExternalSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $base_url
 * @property bool $enabled
 * @property string $attribution
 * @property CoverPolicy $cover_policy
 * @property array<string, mixed> $display_policy
 * @property array<string, mixed>|null $sync_cursor
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'key',
    'name',
    'base_url',
    'enabled',
    'attribution',
    'cover_policy',
    'display_policy',
    'sync_cursor',
    'last_synced_at',
])]
class ExternalSource extends Model
{
    /** @use HasFactory<ExternalSourceFactory> */
    use HasFactory;

    /** The synthetic source that holds the readers' own rating aggregate in `media_metrics` (see App\Catalog\MediaRatings). */
    public const READERS = 'bookflix';

    /** The sources no provider backs: they carry data Bookflix derives itself, so no admin manages, enables or syncs them. */
    public const SYNTHETIC = ['isbn', self::READERS];

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'enabled' => false,
        'cover_policy' => 'none',
        'display_policy' => '{}',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'cover_policy' => CoverPolicy::class,
            'display_policy' => 'array',
            'sync_cursor' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * The sources an admin manages. The synthetic ones (`isbn` only carries ISBN ids for matching, `bookflix` the readers' rating aggregate)
     * are never listed, enabled or synced.
     *
     * @param  Builder<ExternalSource>  $query
     */
    public function scopeProviders(Builder $query): void
    {
        $query->whereNotIn('key', self::SYNTHETIC);
    }

    /**
     * @return HasMany<IngestionRun, $this>
     */
    public function ingestionRuns(): HasMany
    {
        return $this->hasMany(IngestionRun::class);
    }
}
