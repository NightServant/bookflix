<?php

namespace App\Ai\Assistant;

use Illuminate\Support\Str;

/**
 * One assistant chat, kept in the session (never the database): the last ten plain messages, whether the reader lets the assistant use their
 * library, and a random token. `hashedUser()` is all the provider ever sees of who is asking.
 */
final class Conversation
{
    /** The most messages kept; older ones are dropped. */
    public const MAX_MESSAGES = 10;

    /**
     * @param  list<array{role: 'user'|'assistant', text: string, library: bool}>  $messages  `library` marks a turn the library tools took part in.
     */
    public function __construct(
        public bool $useLibrary = false,
        private array $messages = [],
        private ?string $token = null,
    ) {
        $this->token ??= Str::random(40);
    }

    public function add(string $role, string $text, bool $library = false): void
    {
        $this->messages[] = ['role' => $role === 'assistant' ? 'assistant' : 'user', 'text' => $text, 'library' => $library];
        $this->messages = array_slice($this->messages, -self::MAX_MESSAGES);
    }

    /**
     * The messages to send back to the model. Turns that used the library are left out while it is not allowed, so toggling it off cuts the
     * library titles out of what the provider sees.
     *
     * @return list<array{role: 'user'|'assistant', text: string}>
     */
    public function history(bool $libraryAllowed): array
    {
        $kept = array_filter($this->messages, fn (array $message): bool => $libraryAllowed || ! $message['library']);

        return array_values(array_map(fn (array $message): array => ['role' => $message['role'], 'text' => $message['text']], $kept));
    }

    /**
     * Every kept message, for the page to show.
     *
     * @return list<array{role: 'user'|'assistant', text: string, library: bool}>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * The OpenAI `user` field: a hash of this chat's random token, never an email, name or id.
     */
    public function hashedUser(): string
    {
        return hash('sha256', (string) $this->token);
    }
}
