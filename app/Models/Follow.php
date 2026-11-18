<?php

namespace App\Models;

use Database\Factories\FollowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One reader following another (see App\Actions\Social for the only writers). The pair is the primary key, so a row has no id of its own.
 *
 * @property int $follower_id
 * @property int $followed_id
 * @property Carbon|null $created_at
 */
class Follow extends Model
{
    /** @use HasFactory<FollowFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $primaryKey = null;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<User, $this>
     */
    public function follower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follower_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function followed(): BelongsTo
    {
        return $this->belongsTo(User::class, 'followed_id');
    }
}
