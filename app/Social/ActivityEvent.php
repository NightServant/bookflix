<?php

namespace App\Social;

use Illuminate\Support\Carbon;

/**
 * One line of the activity feed, already limited to what the viewer may see. `media` is set for story events, `collection` for a collection
 * event (its `cover` is the first story in it the viewer may see). A spoiler review has `hasSpoilers` and never an `excerpt`.
 */
final readonly class ActivityEvent
{
    /**
     * @param  array{id: int, name: string, handle: string|null, avatar_path: string|null}  $actor
     * @param  array{id: int, slug: string, title: string, cover: string|null}|null  $media
     * @param  array{id: int, slug: string, name: string, cover: string|null}|null  $collection
     */
    public function __construct(
        public ActivityType $type,
        public array $actor,
        public ?array $media,
        public ?array $collection,
        public Carbon $at,
        public ?string $excerpt = null,
        public bool $hasSpoilers = false,
    ) {}
}
