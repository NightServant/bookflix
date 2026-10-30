<?php

namespace App\Jobs;

use App\Enums\InteractionEvent;
use App\Models\User;
use App\Models\UserInteraction;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Insert one interaction. Queued by InteractionRecorder, which has already checked that the reader allows it; the job checks again, because a
 * reader may have opted out, or been deleted, while it waited.
 */
class RecordInteraction implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly int $userId,
        public readonly InteractionEvent $event,
        public readonly ?int $mediaId,
        public readonly array $context,
        public readonly ?float $value,
        public readonly CarbonInterface $occurredAt,
    ) {}

    public function handle(): void
    {
        if (! User::find($this->userId)?->personalizationEnabled()) {
            return;
        }

        UserInteraction::create([
            'user_id' => $this->userId,
            'media_id' => $this->mediaId,
            'event' => $this->event,
            'value' => $this->value,
            'context' => $this->context,
            'occurred_at' => $this->occurredAt,
        ]);
    }
}
