<?php

namespace App\Models;

use App\Enums\ReviewState;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A reader's review of a story (see App\Actions\Social for the only writers). The body is Markdown and is only ever shown through App\Support\Markdown::safe.
 *
 * @property int $id
 * @property int $user_id
 * @property int $media_id
 * @property string $body
 * @property bool $has_spoilers
 * @property ReviewState $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['user_id', 'media_id', 'body', 'has_spoilers'])]
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'has_spoilers' => false,
        'status' => 'published',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'has_spoilers' => 'boolean',
            'status' => ReviewState::class,
        ];
    }

    /**
     * The reviews of a story this viewer may see, newest first: published ones by active, non-admin authors, plus the viewer's own review whatever its status.
     * The same test as ReviewPolicy::view, for a query. (Whether the viewer may see the story at all is the caller's question, through Media::visibleTo.)
     *
     * @param  Builder<Review>  $query
     */
    public function scopeVisibleOnStory(Builder $query, Media $media, ?User $viewer): void
    {
        $query->where('reviews.media_id', $media->id)
            ->where(fn (Builder $visible) => $visible
                ->where(fn (Builder $published) => $published
                    ->where('reviews.status', ReviewState::Published->value)
                    ->whereHas('user', fn (Builder $author) => $author->active()->where('users.is_admin', false)))
                ->when($viewer !== null, fn (Builder $own) => $own->orWhere('reviews.user_id', $viewer->id)))
            ->orderByDesc('reviews.created_at')->orderByDesc('reviews.id');
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
