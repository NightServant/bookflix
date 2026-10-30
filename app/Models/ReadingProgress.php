<?php

namespace App\Models;

use Database\Factories\ReadingProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Where one reader is in one story: the last percent, page or chapter they logged, and when. The primary key is the pair (user, media), so a
 * save is addressed by both. Written only by App\Actions\Library\LogProgress.
 *
 * @property int $user_id
 * @property int $media_id
 * @property int|null $content_source_id
 * @property array<string, mixed>|null $locator
 * @property int|null $page
 * @property string|null $chapter
 * @property string $percent
 * @property Carbon $last_read_at
 */
#[Fillable(['user_id', 'media_id', 'content_source_id', 'locator', 'page', 'chapter', 'percent', 'last_read_at'])]
class ReadingProgress extends Model
{
    /** @use HasFactory<ReadingProgressFactory> */
    use HasFactory;

    protected $table = 'reading_progress';

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
        return [
            'locator' => 'array',
            'page' => 'integer',
            'percent' => 'decimal:2',
            'last_read_at' => 'datetime',
        ];
    }

    /**
     * The percent as a reader reads it: "42.5", "30", never "42.50".
     */
    public static function label(float|string $percent): string
    {
        return rtrim(rtrim(number_format((float) $percent, 2, '.', ''), '0'), '.');
    }

    /**
     * A row is saved and deleted by both halves of its key.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query->where('user_id', $this->getAttribute('user_id'))->where('media_id', $this->getAttribute('media_id'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
