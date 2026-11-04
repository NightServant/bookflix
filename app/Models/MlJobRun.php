<?php

namespace App\Models;

use Database\Factories\MlJobRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Laravel's record of one job it asked the ML service to run. `status` is queued, running, succeeded or failed; a failed run keeps its error.
 *
 * @property int $id
 * @property string $name
 * @property string|null $run_id The ML service's id for the run, once it accepted the job.
 * @property string $status
 * @property array<string, mixed> $params
 * @property array<string, mixed>|null $metrics
 * @property string|null $error
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
#[Fillable(['name', 'run_id', 'status', 'params', 'metrics', 'error', 'started_at', 'finished_at'])]
class MlJobRun extends Model
{
    /** @use HasFactory<MlJobRunFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'params' => 'array',
            'metrics' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
