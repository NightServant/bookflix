<?php

namespace Database\Seeders;

use App\Enums\ModelKind;
use App\Enums\ModelStatus;
use App\Models\ModelVersion;
use Illuminate\Database\Seeder;

class ModelVersionSeeder extends Seeder
{
    /**
     * Seed the weight sets from config as the active versions, so a recommendation always has a `model_version_id` to log. A name that already has
     * any version is left alone: an edited or replaced weight set is never reset by a re-seed.
     */
    public function run(): void
    {
        $sets = [
            'interaction-weights' => [ModelKind::InteractionWeights, config('bookflix.interaction_weights')],
            'hybrid-weights' => [ModelKind::HybridWeights, config('bookflix.hybrid_weights')],
        ];

        foreach ($sets as $name => [$kind, $parameters]) {
            if (ModelVersion::where('name', $name)->exists()) {
                continue;
            }

            ModelVersion::create([
                'name' => $name,
                'version' => 'seed',
                'kind' => $kind,
                'status' => ModelStatus::Active,
                'trained_at' => now(),
                'parameters' => $parameters,
            ]);
        }
    }
}
