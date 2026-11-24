<?php

namespace App\Ai\Assistant;

use App\Models\Media;
use Illuminate\Support\Collection;

/**
 * The assistant's answer: plain text, and the catalog stories to show as cards (only ones the tools returned). `fixed` is true for a line the
 * app wrote (a refusal, a length notice, "not available"): it is shown and saved, but never sent back to the model.
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
        public bool $fixed = false,
    ) {}
}
