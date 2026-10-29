<?php

namespace App\Models;

use Database\Factories\ReadingSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
