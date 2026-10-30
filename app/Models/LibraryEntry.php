<?php

namespace App\Models;

use App\Enums\ReadingStatus;
use Database\Factories\LibraryEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One story in one reader's library: its status and whether it is a favorite (D-6, a favorite is an entry with `favorited_at` set).
 * `created_at` is when the reader added it. Written only by the actions in App\Actions\Library.
 *
 * @property int $id
 * @property int $user_id
 * @property int $media_id
 * @property ReadingStatus $status
 * @property Carbon|null $favorited_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'media_id', 'status', 'favorited_at', 'started_at', 'completed_at'])]
class LibraryEntry extends Model
{
    /** @use HasFactory<LibraryEntryFactory> */
    use HasFactory;

    protected $table = 'user_library';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReadingStatus::class,
            'favorited_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Entries whose story this reader may see: not deleted, not merged away, and past the mature gate (see Media::scopeVisibleTo).
     * A gated entry stays stored and comes back when the reader opts in; it is only left out of lists and counts.
     *
     * @param  Builder<LibraryEntry>  $query
     */
    public function scopeVisibleTo(Builder $query, ?User $viewer): void
    {
        $query->whereHas('media', fn (Builder $media) => $media->unmerged()->visibleTo($viewer));
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
