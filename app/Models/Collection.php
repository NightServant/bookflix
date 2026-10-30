<?php

namespace App\Models;

use App\Enums\Visibility;
use Database\Factories\CollectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A reader's named list of stories. Private until the owner makes it public; an editorial one (set by an admin) is a staff pick for everyone.
 * The slug is unique per owner and stays when the name changes, so a link to it keeps working. Which stories a viewer sees in it is a separate
 * question from who may view it: every list of its stories goes through CollectionItem::visibleTo (the mature gate).
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property Visibility $visibility
 * @property bool $is_editorial
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'name', 'slug', 'description', 'visibility', 'is_editorial'])]
class Collection extends Model
{
    /** @use HasFactory<CollectionFactory> */
    use HasFactory;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'visibility' => 'private',
        'is_editorial' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => Visibility::class,
            'is_editorial' => 'boolean',
        ];
    }

    /**
     * The collection a reader means by this slug, among those they may view: their own first, then an editorial one, then a public one of
     * another reader (slugs are unique per owner only, so the order settles a clash). A link says which it means: a staff pick passes `$editorial`
     * so a reader's own collection of the same name cannot take its place, and a link to a reader's collection passes `$owner`, so two public
     * "Favorites" never open each other's page. Null when there is none.
     */
    public static function resolveFor(string $slug, User $viewer, bool $editorial = false, ?int $owner = null): ?self
    {
        return static::query()
            ->where('slug', $slug)
            ->viewableBy($viewer)
            ->when($editorial, fn (Builder $query) => $query->where('collections.is_editorial', true))
            ->when($owner !== null, fn (Builder $query) => $query->where('collections.user_id', $owner))
            ->orderByRaw('case when collections.user_id = ? then 0 when collections.is_editorial then 1 else 2 end', [$viewer->id])
            ->orderBy('collections.id')
            ->first();
    }

    /**
     * Collections this reader may view: their own, the editorial ones, and the public ones of readers who are still active. The same rule as
     * CollectionPolicy::view, for a query.
     *
     * @param  Builder<Collection>  $query
     */
    public function scopeViewableBy(Builder $query, ?User $viewer): void
    {
        $query->where(fn (Builder $visible) => $visible
            ->where('collections.is_editorial', true)
            ->when($viewer !== null, fn (Builder $own) => $own->orWhere('collections.user_id', $viewer->id))
            ->orWhere(fn (Builder $public) => $public
                ->where('collections.visibility', Visibility::Public->value)
                ->whereHas('user', fn (Builder $owner) => $owner->active())));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CollectionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CollectionItem::class);
    }
}
