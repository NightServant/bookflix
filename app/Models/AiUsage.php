<?php

namespace App\Models;

use Database\Factories\AiUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tokens one model call used, per user (null for calls without one) and feature. Read by `BudgetGuard` for the daily cap.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $feature
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cached_input_tokens
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'feature', 'model', 'input_tokens', 'output_tokens', 'cached_input_tokens'])]
class AiUsage extends Model
{
    /** @use HasFactory<AiUsageFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'ai_usage';
}
