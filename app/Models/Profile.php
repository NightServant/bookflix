<?php

namespace App\Models;

use App\Enums\ReadingLength;
use App\Enums\Visibility;
use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $avatar_path
 * @property string|null $bio
 * @property list<string> $preferred_languages
 * @property ReadingLength $reading_length
 * @property Visibility $profile_visibility
 * @property Visibility $library_visibility
 * @property bool $personalization_enabled
 * @property bool $show_mature The reader asked to see mature and adult titles; counts only with `adult_confirmed_at` (see App\Access\MatureAccess).
 * @property Carbon|null $adult_confirmed_at When the reader confirmed they are 18 or older.
 * @property Carbon|null $onboarded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'avatar_path',
    'bio',
    'preferred_languages',
    'reading_length',
    'profile_visibility',
    'library_visibility',
    'personalization_enabled',
    'show_mature',
    'adult_confirmed_at',
    'onboarded_at',
])]
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preferred_languages' => 'array',
            'reading_length' => ReadingLength::class,
            'profile_visibility' => Visibility::class,
            'library_visibility' => Visibility::class,
            'personalization_enabled' => 'boolean',
            'show_mature' => 'boolean',
            'adult_confirmed_at' => 'datetime',
            'onboarded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
