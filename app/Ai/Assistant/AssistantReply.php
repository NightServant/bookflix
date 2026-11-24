<?php

namespace App\Ai\Assistant;

use App\Models\Media;
use Illuminate\Support\Collection;

/**
 * The assistant's answer: plain text, and the catalog stories to show as cards (only ones the tools returned).
 */
final readonly class AssistantReply
{
    /**
     * @param  Collection<int, Media>  $stories
     */
    public function __construct(
        public string $text,
        public Collection $stories,
        public bool $usedLibrary = false,
        public bool $fallback = false,
    ) {}
}
