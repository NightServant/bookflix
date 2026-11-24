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
use App\Models\AssistantConversation;
use App\Models\Media;
use App\Models\ModelVersion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Answers one message of the discovery assistant. The model may call the read-only tools (at most four turns), and what it writes is cleaned
 * (no HTML, links or URLs) and checked against the stories the tools returned, so a title that is not in the catalogue never reaches the reader.
 * When the model cannot answer for any reason the reply is the fixed fallback line and `fallback` is true.
 */
class AssistantRunner
{
    public const MAX_MESSAGE = 2000;

    /** The most tool turns of any mode (Thorough); Quick allows two (AssistantMode). */
    public const MAX_TOOL_TURNS = 4;

    /** The most characters of attachment text sent with one message, all attachments together. */
    public const MAX_ATTACHMENT_TEXT = 20000;

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

    /**
     * Answer one message of a saved chat and save the exchange (the reader's message and the reply). Fixed lines are saved too, flagged so they
     * never go back to the model; a message over the length limit or an empty one is not saved at all. The mode ("task:depth", validated here;
     * unknown is the default) is saved on the answer, and only the names of the attachments are kept (P11-R26).
     *
     * @param  list<Attachment>  $attachments
     */
    public function reply(User $user, AssistantConversation $conversation, string $message, ?string $mode = null, array $attachments = []): AssistantReply
    {
        $message = trim($message);
        $mode = AssistantMode::parse($mode);
        $reply = $this->answer($user, $conversation, $message, $mode, $attachments);

        if ($message !== '' && mb_strlen($message) <= self::MAX_MESSAGE) {
            $conversation->record($message, $reply, $mode->key(), array_map(fn (Attachment $attachment): string => $attachment->name, $attachments));
        }

        return $reply;
    }

    /**
     * @param  list<Attachment>  $attachments
     */
    private function answer(User $user, AssistantConversation $conversation, string $message, AssistantMode $mode, array $attachments): AssistantReply
    {
        if (mb_strlen($message) > self::MAX_MESSAGE) {
            return new AssistantReply(self::TOO_LONG, new Collection, fixed: true);
        }

        if ($message === '') {
            return new AssistantReply(self::UNAVAILABLE, new Collection, fallback: true, fixed: true);
        }

        $blocks = $this->attachmentBlocks($attachments);
        $hasImage = collect($attachments)->contains(fn (Attachment $attachment): bool => $attachment->isImage());

        if (ProhibitedContent::matches($message) || ($blocks !== '' && ProhibitedContent::matches($blocks))) {
            return new AssistantReply(self::REFUSAL, new Collection, fixed: true);
        }

        if ($hasImage && blank(config('bookflix.ai.models.vision'))) {
            return new AssistantReply(self::UNAVAILABLE, new Collection, fallback: true, fixed: true);
        }

        if (! $this->withinLimit($user)) {
            return new AssistantReply(self::UNAVAILABLE, new Collection, fallback: true, fixed: true);
        }

        try {
            return $this->converse($user, $conversation, $message, $mode, $blocks, $attachments);
        } catch (LlmUnavailable) {
            return new AssistantReply(self::UNAVAILABLE, new Collection, fallback: true, fixed: true);
        } catch (Throwable $e) {
            report($e);

            return new AssistantReply(self::UNAVAILABLE, new Collection, fallback: true, fixed: true);
        }
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
     * @param  list<Attachment>  $attachments
     *
     * @throws LlmUnavailable
     */
    private function converse(User $user, AssistantConversation $conversation, string $message, AssistantMode $mode, string $blocks, array $attachments): AssistantReply
    {
        // The base prompt first and byte-stable, then the mode's block (P11-R25).
        $system = $this->prompt()."\n\n".$mode->instruction();
        $libraryAllowed = $conversation->use_library && $user->personalizationEnabled();
        $definitions = array_values(array_filter(
            $this->tools->definitions($user, $conversation),
            fn (array $definition): bool => $mode->allows($definition['function']['name']),
        ));
        $offered = array_column(array_column($definitions, 'function'), 'name');
        $images = array_values(array_filter($attachments, fn (Attachment $attachment): bool => $attachment->isImage()));
        $model = $images === [] ? $mode->model() : 'vision';

        $messages = [];

        foreach ($conversation->history($libraryAllowed) as $past) {
            $messages[] = ['role' => $past['role'], 'content' => $past['role'] === 'user' ? $this->wrap($past['text']) : $past['text']];
        }

        $messages[] = ['role' => 'user', 'content' => $this->userContent($message, $blocks, $images)];

        /** @var array<int, Media> $seen */
        $seen = [];
        $lastSearch = new Collection;
        $usedLibrary = false;

        for ($turn = 0; ; $turn++) {
            // After the last tool turn (two for Quick, four for Thorough) the model gets one more call that may not use tools.
            $final = $turn === $mode->maxToolTurns();
            $response = $this->ask($user, $conversation, $model, $system, $messages, $definitions, $final);

            if ($response->toolCalls === [] || $final) {
                break;
            }

            $messages[] = $response->assistantMessage;

            foreach ($response->toolCalls as $index => $call) {
                $result = $index >= self::MAX_CALLS_PER_TURN
                    ? ToolResult::error('Too many tool calls in one turn.')
                    : (in_array($call->name, $offered, true) ? $this->tools->execute($user, $conversation, $call) : ToolResult::error('Unknown tool.'));

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

        $this->promptVersion($model);

        if ($this->leaksPrompt($raw)) {
            return new AssistantReply(self::OFF_TOPIC, new Collection, $usedLibrary, fixed: true);
        }

        $allowed = new Collection(array_values($seen));
        $text = $this->verifier->scrub($this->plain($raw), $allowed);

        if ($text === '' || $this->leaksPrompt($text)) {
            return new AssistantReply(self::OFF_TOPIC, new Collection, $usedLibrary, fixed: true);
        }

        return new AssistantReply($text, $this->stories($text, $allowed, $lastSearch), $usedLibrary);
    }

    /**
     * @param  'fast'|'smart'|'vision'  $model
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $definitions
     *
     * @throws LlmUnavailable
     */
    private function ask(User $user, AssistantConversation $conversation, string $model, string $system, array $messages, array $definitions, bool $final): LlmResponse
    {
        return $this->llm->complete(new LlmRequest(
            feature: 'assistant',
            model: $model,
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
        return '<message>'.str_replace(['<', '>'], ' ', $text).'</message>';
    }

    /**
     * The reader's new message: its `<message>` block, then the `<attachment>` blocks; with pictures, OpenAI content parts for the vision model.
     *
     * @param  list<Attachment>  $images
     * @return string|list<array<string, mixed>>
     */
    private function userContent(string $message, string $blocks, array $images): string|array
    {
        $text = $this->wrap($message).($blocks === '' ? '' : "\n".$blocks);

        if ($images === []) {
            return $text;
        }

        return [
            ['type' => 'text', 'text' => $text],
            ...array_map(fn (Attachment $image): array => ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$image->mime.';base64,'.$image->base64]], $images),
        ];
    }

    /**
     * The text attachments as data blocks, `<` and `>` neutralized, all together cut to 20,000 characters (P11-R26).
     *
     * @param  list<Attachment>  $attachments
     */
    private function attachmentBlocks(array $attachments): string
    {
        $room = self::MAX_ATTACHMENT_TEXT;
        $blocks = [];

        foreach ($attachments as $attachment) {
            if ($attachment->text === null || $room <= 0) {
                continue;
            }

            $text = mb_substr(str_replace(['<', '>'], ' ', mb_scrub($attachment->text)), 0, $room);
            $room -= mb_strlen($text);
            $name = mb_substr(trim(preg_replace('/[<>"\x00-\x1F\x7F]+/u', ' ', $attachment->name) ?? ''), 0, 100);
            $blocks[] = '<attachment name="'.$name.'">'.$text.'</attachment>';
        }

        return implode("\n", $blocks);
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
        $named = $allowed->filter(fn (Media $story): bool => mb_strlen($story->title) >= 3
            && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($story->title, '/').'(?![\p{L}\p{N}])/iu', $text) === 1);

        return ($named->isNotEmpty() ? $named : $lastSearch->take(3))->take(self::MAX_STORIES)->values();
    }

    private function prompt(): string
    {
        return (string) file_get_contents(app_path(self::PROMPT_FILE));
    }

    /**
     * The `model_versions` row for the current prompt (a hash of its text) and model; a new one becomes the active version of its name.
     */
    private function promptVersion(string $model): ModelVersion
    {
        $label = substr(sha1($this->prompt()), 0, 8).'+'.config('bookflix.ai.models.'.$model);

        return ModelVersion::query()->where('name', 'assistant-prompt')->where('version', $label)->first()
            ?? DB::transaction(function () use ($label): ModelVersion {
                ModelVersion::query()->active('assistant-prompt')->update(['status' => ModelStatus::Retired->value]);

                return ModelVersion::query()->create(['name' => 'assistant-prompt', 'version' => $label, 'kind' => ModelKind::Llm, 'status' => ModelStatus::Active]);
            });
    }
}
