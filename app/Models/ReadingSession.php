<?php

namespace App\Models;

use Carbon\CarbonInterval;
use Database\Factories\ReadingSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One logged stretch of reading (table `reading_history`): when it began and ended, how many seconds it lasted, and the percent it moved from and
 * to. Appended by App\Actions\Library\LogProgress and never changed.
 *
 * @property int $id
 * @property int $user_id
 * @property int $media_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property int $seconds
 * @property string $from_percent
 * @property string $to_percent
 * @property int $chapters_completed
 */
#[Fillable(['user_id', 'media_id', 'started_at', 'ended_at', 'seconds', 'from_percent', 'to_percent', 'chapters_completed'])]
class ReadingSession extends Model
{
    /** @use HasFactory<ReadingSessionFactory> */
    use HasFactory;

    protected $table = 'reading_history';

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'seconds' => 'integer',
            'from_percent' => 'decimal:2',
            'to_percent' => 'decimal:2',
            'chapters_completed' => 'integer',
        ];
    }

    /**
     * How long a session lasted as a reader reads it: "1h 30m", "25m", "45s". Hours do not roll over into days, and seconds only show
     * for a session shorter than a minute.
     */
    public static function duration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return CarbonInterval::hours($hours)->minutes($minutes)->seconds($hours + $minutes > 0 ? 0 : $seconds)->forHumans(short: true, parts: 2);
    }

    /**
     * Sessions whose story this reader may see: not deleted, not merged away, and past the mature gate (see Media::scopeVisibleTo). A gated
     * session stays stored and comes back when the reader opts in; it is only left out of the history and its figures.
     *
     * @param  Builder<ReadingSession>  $query
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
