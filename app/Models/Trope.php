<?php

namespace App\Models;

use Database\Factories\TropeFactory;
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
 * @property string|null $group
 * @property int $sort
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'group', 'sort', 'description'])]
class Trope extends Model
{
    /** @use HasFactory<TropeFactory> */
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
        return $this->belongsToMany(Media::class, 'media_tropes')->withTimestamps();
    }
}
