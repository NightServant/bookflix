<?php

namespace App\Ai\Assistant;

use App\Ai\LlmClient;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;
use App\Ai\LlmUnavailable;
use App\Ai\TitleVerifier;
use App\Catalog\Ingestion\ProhibitedContent;
use App\Enums\ModelKind;
use App\Enums\ModelStatus;
use App\Models\Media;
use App\Models\ModelVersion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Answers one message of the discovery assistant. The model may call the read-only tools (at most four turns), and what it writes is cleaned
 * (no HTML, links or URLs) and checked against the stories the tools returned, so a title that is not in the catalogue never reaches the reader.
 * When the model cannot answer for any reason the reply is the fixed fallback line and `fallback` is true.
 */
class AssistantRunner
{
    public const MAX_MESSAGE = 2000;

    public const MAX_TOOL_TURNS = 4;

    /** Tool calls run per model turn; the rest of a turn's calls get an error result. */
    private const MAX_CALLS_PER_TURN = 4;

    /** The most story cards one reply shows. */
    private const MAX_STORIES = 6;

    private const PROMPT_FILE = 'Ai/Prompts/assistant.md';

    /** A line of the system prompt that must never appear in a reply (the prompt's fingerprint). */
    private const CANARY = 'BKF-ASSIST-7731';

    public const TOO_LONG = 'Please keep it under 2,000 characters.';

    public const REFUSAL = "I can't help with that.";

    public const UNAVAILABLE = "The assistant isn't available right now. Try Search instead.";

    public const OFF_TOPIC = 'I can only help with finding stories in Bookflix.';

    public function __construct(
        private LlmClient $llm,
        private AssistantTools $tools,
        private TitleVerifier $verifier,
    ) {}

    public function reply(User $user, Conversation $conversation, string $message): AssistantReply
    {
        $message = trim($message);

        if (mb_strlen($message) > self::MAX_MESSAGE) {
            return new AssistantReply(self::TOO_LONG, new Collection);
        }

        if ($message === '') {
            return new AssistantReply(self::UNAVAILABLE, new Collection, fallback: true);
        }

        if (ProhibitedContent::matches($message)) {
            return new AssistantReply(self::REFUSAL, new Collection);
        }

        if (! $this->withinLimit($user)) {
            return new AssistantReply(self::UNAVAILABLE, new Collection, fallback: true);
        }

        try {
            $reply = $this->converse($user, $conversation, $message);
        } catch (LlmUnavailable) {
            return new AssistantReply(self::UNAVAILABLE, new Collection, fallback: true);
        }

        $conversation->add('user', $message, $reply->usedLibrary);
        $conversation->add('assistant', $reply->text, $reply->usedLibrary);

        return $reply;
    }

    /**
     * One message per call counts against the `ai-assistant` limiter (20 an hour per reader).
     */
    private function withinLimit(User $user): bool
    {
        $request = new Request;
        $request->setUserResolver(fn (): User => $user);
        $limit = RateLimiter::limiter('ai-assistant')($request);

        return RateLimiter::attempt('ai-assistant:'.$limit->key, $limit->maxAttempts, fn (): bool => true, $limit->decaySeconds);
    }

    /**
     * @throws LlmUnavailable
     */
    private function converse(User $user, Conversation $conversation, string $message): AssistantReply
    {
        $system = $this->prompt();
        $libraryAllowed = $conversation->useLibrary && $user->personalizationEnabled();
        $definitions = $this->tools->definitions($user, $conversation);

        $messages = [];

        foreach ($conversation->history($libraryAllowed) as $past) {
            $messages[] = ['role' => $past['role'], 'content' => $past['role'] === 'user' ? $this->wrap($past['text']) : $past['text']];
        }

        $messages[] = ['role' => 'user', 'content' => $this->wrap($message)];

        /** @var array<int, Media> $seen */
        $seen = [];
        $lastSearch = new Collection;
        $usedLibrary = false;

        for ($turn = 0; $turn <= self::MAX_TOOL_TURNS; $turn++) {
            // After the fourth tool turn the model gets one last call that may not use tools.
            $final = $turn === self::MAX_TOOL_TURNS;
            $response = $this->ask($user, $conversation, $system, $messages, $definitions, $final);

            if ($response->toolCalls === [] || $final) {
                break;
            }

            $messages[] = $response->assistantMessage;

            foreach ($response->toolCalls as $index => $call) {
                $result = $index >= self::MAX_CALLS_PER_TURN
                    ? ToolResult::error('Too many tool calls in one turn.')
                    : $this->tools->execute($user, $conversation, $call);

                $usedLibrary = $usedLibrary || $result->usedLibrary;

                foreach ($result->media as $story) {
                    $seen[$story->id] = $story;
                }

                if ($result->isSearch && $result->ok && $result->media->isNotEmpty()) {
                    $lastSearch = $result->media;
                }

                $messages[] = ['role' => 'tool', 'tool_call_id' => $call->id, 'content' => $result->content];
            }
        }

        $raw = trim((string) $response->text);

        if ($raw === '') {
            throw new LlmUnavailable('The assistant answered with no text.');
        }

        $this->promptVersion();

        if ($this->leaksPrompt($raw)) {
            return new AssistantReply(self::OFF_TOPIC, new Collection, $usedLibrary);
        }

        $allowed = new Collection(array_values($seen));
        $text = $this->verifier->scrub($this->plain($raw), $allowed);

        if ($text === '' || $this->leaksPrompt($text)) {
            return new AssistantReply(self::OFF_TOPIC, new Collection, $usedLibrary);
        }

        return new AssistantReply($text, $this->stories($text, $allowed, $lastSearch), $usedLibrary);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $definitions
     *
     * @throws LlmUnavailable
     */
    private function ask(User $user, Conversation $conversation, string $system, array $messages, array $definitions, bool $final): LlmResponse
    {
        return $this->llm->complete(new LlmRequest(
            feature: 'assistant',
            model: 'smart',
            system: $system,
            messages: $messages,
            tools: $definitions,
            user: $user,
            toolChoice: $final ? 'none' : null,
            logContent: $user->personalizationEnabled(),
            endUser: $conversation->hashedUser(),
        ));
    }

    private function wrap(string $text): string
    {
        return '<message>'.str_replace(['<message>', '</message>'], '', $text).'</message>';
    }

    /**
     * The model's text as plain text: HTML tags, markdown links and images (the link text stays) and bare URLs are gone.
     */
    private function plain(string $text): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/!?\[([^\]\n]*)\]\([^)\n]*\)/u', '$1', $text) ?? '';
        $text = preg_replace('~\b[a-z][a-z0-9+.-]*://\S+|\bwww\.\S+~iu', '', $text) ?? '';
        $text = preg_replace(['/\(\s*\)|\[\s*\]/u', '/[ \t]+([,.;:!?])/u', '/[ \t]{2,}/u', '/\n{3,}/u'], ['', '$1', ' ', "\n\n"], $text) ?? '';

        return trim($text);
    }

    private function leaksPrompt(string $text): bool
    {
        return str_contains(preg_replace('/[^a-z0-9]+/', '', strtolower($text)) ?? '', preg_replace('/[^a-z0-9]+/', '', strtolower(self::CANARY)));
    }

    /**
     * The stories to show as cards: those the tools returned whose title is in the text, else the first few of the last search that found some.
     *
     * @param  Collection<int, Media>  $allowed
     * @param  Collection<int, Media>  $lastSearch
     * @return Collection<int, Media>
     */
    private function stories(string $text, Collection $allowed, Collection $lastSearch): Collection
    {
        $named = $allowed->filter(fn (Media $story): bool => $story->title !== '' && mb_stripos($text, $story->title) !== false);

        return ($named->isNotEmpty() ? $named : $lastSearch->take(3))->take(self::MAX_STORIES)->values();
    }

    private function prompt(): string
    {
        return (string) file_get_contents(app_path(self::PROMPT_FILE));
    }

    /**
     * The `model_versions` row for the current prompt (a hash of its text) and model; a new one becomes the active version of its name.
     */
    private function promptVersion(): ModelVersion
    {
        $label = substr(sha1($this->prompt()), 0, 8).'+'.config('bookflix.ai.models.smart');

        return ModelVersion::query()->where('name', 'assistant-prompt')->where('version', $label)->first()
            ?? DB::transaction(function () use ($label): ModelVersion {
                ModelVersion::query()->active('assistant-prompt')->update(['status' => ModelStatus::Retired->value]);

                return ModelVersion::query()->create(['name' => 'assistant-prompt', 'version' => $label, 'kind' => ModelKind::Llm, 'status' => ModelStatus::Active]);
            });
    }
}
