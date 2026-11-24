<?php

namespace App\Ai;

/**
 * The model's answer. `assistantMessage` is the raw message to append to the conversation when a tool loop continues.
 */
final readonly class LlmResponse
{
    /**
     * @param  list<LlmToolCall>  $toolCalls
     * @param  array<string, mixed>  $assistantMessage
     */
    public function __construct(
        public ?string $text,
        public array $toolCalls,
        public string $finishReason,
        public bool $truncated,
        public int $inputTokens,
        public int $outputTokens,
        public int $cachedInputTokens,
        public array $assistantMessage,
    ) {}
}
