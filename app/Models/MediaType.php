<?php

namespace App\Models;

use App\Enums\MediaFamily;
use App\Enums\ReadingMode;
use Database\Factories\MediaTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property MediaFamily $family
 * @property ReadingMode $reading_mode
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['slug', 'name', 'family', 'reading_mode'])]
class MediaType extends Model
{
    /** @use HasFactory<MediaTypeFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'family' => MediaFamily::class,
            'reading_mode' => ReadingMode::class,
        ];
    }

    /**
     * @return HasMany<Media, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }
}
