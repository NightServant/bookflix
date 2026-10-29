<?php

namespace App\Models;

use Database\Factories\UserPreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One thing a reader said they like: a media type, genre, theme, trope, demographic or creator, named by the morph map in AppServiceProvider
 * (`media_type`, `genre`, ...). Written by App\Actions\Preferences\SavePreferences from onboarding and Settings, Preferences; Phase 6 reads them
 * to start recommendations before there is any history. `weight` starts at 1.
 *
 * @property int $id
 * @property int $user_id
 * @property string $preferable_type
 * @property int $preferable_id
 * @property int $weight
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'preferable_type', 'preferable_id', 'weight'])]
class UserPreference extends Model
{
    /** @use HasFactory<UserPreferenceFactory> */
    use HasFactory;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'weight' => 1,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight' => 'integer',
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
     * @return MorphTo<Model, $this>
     */
    public function preferable(): MorphTo
    {
        return $this->morphTo();
    }
}
