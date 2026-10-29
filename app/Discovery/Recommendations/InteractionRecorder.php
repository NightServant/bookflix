<?php

namespace App\Discovery\Recommendations;

use App\Enums\InteractionEvent;
use App\Jobs\RecordInteraction;
use App\Models\Media;
use App\Models\User;

/**
 * The one place an interaction is recorded from. A reader who opted out of personalization produces nothing, whichever path asks, and the write
 * is queued, so a page never waits for it. What is kept is narrow on purpose: no IP address, no user agent, and only the context keys below.
 */
class InteractionRecorder
{
    /** The context keys that are kept; anything else a caller passes is dropped. */
    public const CONTEXT_KEYS = ['surface', 'shelf', 'position', 'recommendation_id', 'search_query_id'];

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(User $user, InteractionEvent $event, ?Media $media = null, array $context = [], ?float $value = null): void
    {
        if (! $user->personalizationEnabled()) {
            return;
        }

        RecordInteraction::dispatch($user->id, $event, $media?->id, array_intersect_key($context, array_flip(self::CONTEXT_KEYS)), $value, now());
    }
}
