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
     * @param  'none'|'auto'|null  $toolChoice  Sent as `tool_choice` when there are tools; `none` forces a plain answer.
     * @param  bool  $logContent  False when the text belongs to someone who opted out of personalization: with `log_payloads` on, only the feature and sizes are logged.
     * @param  string|null  $endUser  Sent as the OpenAI `user` field: an opaque hash, never an email, name or id.
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
        public ?string $toolChoice = null,
        public bool $logContent = true,
        public ?string $endUser = null,
    ) {}
}
