<?php

namespace App\Models;

use App\Enums\ModelKind;
use App\Enums\ModelStatus;
use Database\Factories\ModelVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One trained or configured version of a model, or of a weight set (`hybrid-weights`, `interaction-weights`). The Python service writes the trained
 * ones; Laravel reads the active version of each name. At most one version per name is active.
 *
 * @property int $id
 * @property string $name
 * @property string $version
 * @property ModelKind $kind
 * @property ModelStatus $status
 * @property Carbon|null $trained_at
 * @property string|null $dataset_ref
 * @property array<string, mixed> $parameters
 * @property array<string, mixed> $metrics
 * @property string|null $artifact_uri
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'version', 'kind', 'status', 'trained_at', 'dataset_ref', 'parameters', 'metrics', 'artifact_uri'])]
class ModelVersion extends Model
{
    /** @use HasFactory<ModelVersionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ModelKind::class,
            'status' => ModelStatus::class,
            'trained_at' => 'datetime',
            'parameters' => 'array',
            'metrics' => 'array',
        ];
    }

    /**
     * The active version of one name.
     *
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query, string $name): void
    {
        $query->where('name', $name)->where('status', ModelStatus::Active->value);
    }

    public static function activeFor(string $name): ?self
    {
        return static::query()->active($name)->first();
    }
}
