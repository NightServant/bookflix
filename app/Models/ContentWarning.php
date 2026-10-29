<?php

namespace App\Models;

use Database\Factories\ContentWarningFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * One entry of the content warning vocabulary. What a work does about it (present, verified absent, unknown, disputed) lives on the
 * `media_content_warnings` pivot, never here.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $sort
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'sort', 'description'])]
class ContentWarning extends Model
{
    /** @use HasFactory<ContentWarningFactory> */
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
     * @return BelongsToMany<Media, $this, MediaContentWarning, 'pivot'>
     */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'media_content_warnings')
            ->using(MediaContentWarning::class)
            ->withPivot(['status', 'source'])
            ->withTimestamps();
    }
}
