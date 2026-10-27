<?php

namespace App\Models;

use Database\Factories\SearchQueryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id Null when the reader opted out of personalization.
 * @property string $session_hash
 * @property string $query
 * @property string $normalized
 * @property array<string, mixed> $filters
 * @property array<string, mixed>|null $intent
 * @property int $results_count
 * @property int|null $previous_query_id The search this one refined, when it followed it in the same session.
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'session_hash', 'query', 'normalized', 'filters', 'intent', 'results_count', 'previous_query_id'])]
class SearchQuery extends Model
{
    /** @use HasFactory<SearchQueryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'intent' => 'array',
            'results_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SearchQuery, $this>
     */
    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_query_id');
    }
}
