<?php

namespace App\Ai;

/**
 * One function call the model asked for. `arguments` is null when the model's arguments were not a JSON object.
 */
final readonly class LlmToolCall
{
    /**
     * @param  array<string, mixed>|null  $arguments
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?array $arguments,
    ) {}
}
