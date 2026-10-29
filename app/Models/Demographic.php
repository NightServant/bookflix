<?php

namespace App\Models;

use Database\Factories\DemographicFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $sort
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'sort', 'description'])]
class Demographic extends Model
{
    /** @use HasFactory<DemographicFactory> */
    use HasFactory;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort' => 0,
    ];

    /**
     * @return BelongsToMany<Media, $this, Pivot, 'pivot'>
     */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'media_demographics')->withTimestamps();
    }
}
