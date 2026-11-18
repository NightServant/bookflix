<?php

namespace App\Models;

use App\Actions\Ratings\RecalculateRatedMedia;
use App\Enums\ReadingLength;
use App\Enums\Visibility;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property bool $is_admin
 * @property Carbon|null $deactivated_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_admin' => false,
    ];

    protected static function booted(): void
    {
        // A deactivated reader is left out of every rating (see RecalculateMediaRating), so deactivating or reactivating one, whichever way it is done,
        // refreshes the stories they rated.
        static::updated(function (User $user): void {
            if ($user->wasChanged('deactivated_at')) {
                app(RecalculateRatedMedia::class)->handle($user);
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * The user's reading profile; unsaved column defaults when no row exists yet.
     *
     * @return HasOne<Profile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class)->withDefault([
            'preferred_languages' => [],
            'reading_length' => ReadingLength::Any,
            'profile_visibility' => Visibility::Private,
            'library_visibility' => Visibility::Private,
            'personalization_enabled' => true,
            'show_mature' => false,
        ]);
    }

    /**
     * The stories in the reader's library (see App\Actions\Library for the only writers).
     *
     * @return HasMany<LibraryEntry, $this>
     */
    public function library(): HasMany
    {
        return $this->hasMany(LibraryEntry::class);
    }

    /**
     * The reader's own collections (see App\Actions\Library for the only writers).
     *
     * @return HasMany<Collection, $this>
     */
    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class);
    }

    /**
     * The reader's own scores (see App\Actions\Ratings\RateMedia for the only writer).
     *
     * @return HasMany<Rating, $this>
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    /**
     * What the reader said they like (see App\Actions\Preferences\SavePreferences for the only writer).
     *
     * @return HasMany<UserPreference, $this>
     */
    public function preferences(): HasMany
    {
        return $this->hasMany(UserPreference::class);
    }

    /**
     * The readers who follow this one, as stored (see App\Actions\Social for the only writers). Lists and counts shown to anyone use listedFollowers().
     *
     * @return BelongsToMany<User, $this>
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'follows', 'followed_id', 'follower_id')->withPivot('created_at');
    }

    /**
     * The readers this one follows, as stored. Lists and counts shown to anyone use listedFollowing().
     *
     * @return BelongsToMany<User, $this>
     */
    public function following(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'follows', 'follower_id', 'followed_id')->withPivot('created_at');
    }

    /**
     * Followers who may be shown (P10-R4): active, non-admin, public and not moderator-hidden, for every viewer including the owner.
     *
     * @return Builder<User>
     */
    public function listedFollowers(): Builder
    {
        return self::query()->listed()->whereIn('users.id', Follow::query()->select('follower_id')->where('followed_id', $this->id));
    }

    /**
     * Followed readers who may be shown (P10-R4), by the same rule as listedFollowers().
     *
     * @return Builder<User>
     */
    public function listedFollowing(): Builder
    {
        return self::query()->listed()->whereIn('users.id', Follow::query()->select('followed_id')->where('follower_id', $this->id));
    }

    /**
     * Where a signed-in user is sent "home": the admin dashboard for an admin, Fortify's home path (Home) for a reader.
     */
    public function homePath(): string
    {
        return $this->is_admin ? route('admin.index', absolute: false) : Fortify::redirects('login');
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /**
     * Limit the query to accounts that have not been deactivated.
     *
     * @param  Builder<User>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('deactivated_at');
    }

    /**
     * Limit the query to readers who may be listed publicly: active, not an admin, with a public profile that moderators have not hidden.
     * The same test as the `view-profile` gate, in SQL.
     *
     * @param  Builder<User>  $query
     */
    public function scopeListed(Builder $query): void
    {
        $query->active()
            ->where('users.is_admin', false)
            ->whereExists(fn ($profiles) => $profiles->selectRaw('1')->from('profiles')
                ->whereColumn('profiles.user_id', 'users.id')
                ->where('profiles.profile_visibility', Visibility::Public->value)
                ->whereNull('profiles.moderation_hidden_at'));
    }

    /**
     * Whether interactions may be recorded and used for personalization.
     */
    public function personalizationEnabled(): bool
    {
        return $this->profile->personalization_enabled;
    }

    /**
     * Public URL of the uploaded avatar, or null when none was uploaded.
     */
    public function avatarUrl(): ?string
    {
        $path = $this->profile->avatar_path;

        return $path ? Storage::disk(config('bookflix.avatar_disk'))->url($path) : null;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
