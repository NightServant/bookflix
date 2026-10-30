<?php

namespace Database\Factories;

use App\Enums\ReadingStatus;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LibraryEntry>
 */
class LibraryEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'media_id' => Media::factory(),
            'status' => ReadingStatus::WantToRead,
        ];
    }

    /**
     * An entry at this status, with the dates a real change to it would have stamped.
     */
    public function status(ReadingStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'started_at' => in_array($status, [ReadingStatus::Reading, ReadingStatus::Completed], true) ? now() : null,
            'completed_at' => $status === ReadingStatus::Completed ? now() : null,
        ]);
    }

    /**
     * A favorite.
     */
    public function favorite(): static
    {
        return $this->state(fn () => ['favorited_at' => now()]);
    }
}
