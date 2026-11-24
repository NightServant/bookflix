<?php

namespace App\Ai;

use App\Models\User;

/**
 * One call to the model. `system` is the static prompt and is sent first, byte-stable, so a provider's prefix caching can apply.
 */
final readonly class LlmRequest
{
    /**
     * @param  'fast'|'smart'  $model  The tier alias; the model id comes from config.
     * @param  list<array<string, mixed>>  $messages  OpenAI message arrays.
     * @param  list<array<string, mixed>>  $tools  OpenAI function definitions (`{type: "function", function: {...}}`).
     */
    public function __construct(
        public string $feature,
        public string $model,
        public string $system,
        public array $messages,
        public array $tools = [],
        public ?int $maxTokens = null,
        public bool $json = false,
        public ?User $user = null,
    ) {}
}
