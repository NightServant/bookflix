<?php

namespace Database\Factories;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reporter_id' => User::factory(),
            'reportable_type' => 'review',
            'reportable_id' => Review::factory(),
            'reason' => ReportReason::Spam,
            'details' => null,
            'status' => ReportStatus::Open,
        ];
    }

    /**
     * A report of this review, collection or profile.
     */
    public function of(Model $target): static
    {
        return $this->state(fn () => ['reportable_type' => $target->getMorphClass(), 'reportable_id' => $target->getKey()]);
    }

    /**
     * Dealt with by a moderator.
     */
    public function dismissed(): static
    {
        return $this->state(fn () => ['status' => ReportStatus::Dismissed, 'handled_by' => User::factory()->admin(), 'handled_at' => now()]);
    }
}
