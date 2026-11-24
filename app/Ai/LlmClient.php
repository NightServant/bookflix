<?php

namespace App\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * A thin client for an OpenAI-compatible Chat Completions API. Every failure is `LlmUnavailable`; callers fall back to the non-AI path.
 */
class LlmClient
{
    public function __construct(private KillSwitch $killSwitch, private BudgetGuard $budget) {}

    /**
     * @throws LlmUnavailable
     */
    public function complete(LlmRequest $request): LlmResponse
    {
        $baseUrl = (string) config('bookflix.ai.base_url');
        $key = (string) config('bookflix.ai.api_key');
        $model = (string) config('bookflix.ai.models.'.$request->model);

        if (! config('bookflix.ai.enabled')) {
            throw new LlmUnavailable('AI is switched off.');
        }

        if ($this->killSwitch->active()) {
            throw new LlmUnavailable('AI is stopped by the kill switch.');
        }

        if ($baseUrl === '' || $key === '' || $model === '') {
            throw new LlmUnavailable('AI is not configured.');
        }

        $this->budget->check($request->user, $request->feature);

        $body = $this->body($request, $model);

        if (config('bookflix.ai.log_payloads')) {
            Log::debug('AI request', $request->logContent
                ? ['feature' => $request->feature, 'body' => $body]
                : ['feature' => $request->feature, 'messages' => count($body['messages']), 'chars' => strlen((string) json_encode($body))]);
        }

        $response = $this->send($baseUrl, $key, $body);
        $json = $response->json();

        if (config('bookflix.ai.log_payloads')) {
            Log::debug('AI response', $request->logContent
                ? ['feature' => $request->feature, 'body' => $json]
                : ['feature' => $request->feature, 'chars' => strlen((string) json_encode($json))]);
        }

        $response = $this->parse($json);

        $this->budget->record($request->user, $request->feature, $model, $response->inputTokens, $response->outputTokens, $response->cachedInputTokens);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(LlmRequest $request, string $model): array
    {
        $maxTokens = $request->maxTokens ?? (int) config('bookflix.ai.max_output_tokens.'.$request->feature, 300);

        $body = [
            'model' => $model,
            'messages' => [['role' => 'system', 'content' => $request->system], ...$request->messages],
            'max_tokens' => $maxTokens,
        ];

        if ($request->endUser !== null) {
            $body['user'] = $request->endUser;
        }

        if ($request->tools !== []) {
            $body['tools'] = $request->tools;

            if ($request->toolChoice !== null) {
                $body['tool_choice'] = $request->toolChoice;
            }
        }

        // ponytail: json_schema is sent as json_object until a feature needs a schema; add the schema to LlmRequest then.
        if ($request->json && config('bookflix.ai.json_mode') !== 'none') {
            $body['response_format'] = ['type' => 'json_object'];
        }

        return $body;
    }

    /**
     * One bounded retry for a 429 (honouring retry-after, at most 5 seconds) and for a 5xx or a connection failure.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws LlmUnavailable
     */
    private function send(string $baseUrl, string $key, array $body): Response
    {
        for ($attempt = 1; ; $attempt++) {
            $wait = 1;

            try {
                $response = Http::baseUrl(rtrim($baseUrl, '/'))
                    ->withToken($key)
                    ->timeout((float) config('bookflix.ai.timeout'))
                    ->acceptJson()
                    ->post('/chat/completions', $body);
            } catch (ConnectionException) {
                $response = null;
            }

            if ($response?->successful()) {
                return $response;
            }

            $status = $response?->status();
            $retryable = $response === null || $status === 429 || $status >= 500;

            if (! $retryable || $attempt >= 2) {
                throw new LlmUnavailable($status === null ? 'The AI service did not answer.' : "The AI service answered HTTP {$status}.");
            }

            if ($status === 429) {
                $wait = min(max((int) $response->header('retry-after'), 1), 5);
            }

            Sleep::for($wait)->seconds();
        }
    }

    /**
     * @throws LlmUnavailable
     */
    private function parse(mixed $json): LlmResponse
    {
        $choice = is_array($json) ? ($json['choices'][0] ?? null) : null;
        $message = is_array($choice) ? ($choice['message'] ?? null) : null;

        if (! is_array($message)) {
            throw new LlmUnavailable('The AI service answered out of shape.');
        }

        $finishReason = is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : 'stop';

        if ($finishReason === 'content_filter') {
            throw new LlmUnavailable('The AI service filtered the answer.');
        }

        $content = is_string($message['content'] ?? null) && trim($message['content']) !== '' ? $message['content'] : null;
        $toolCalls = [];

        foreach (is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [] as $call) {
            $name = is_array($call) ? ($call['function']['name'] ?? null) : null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $arguments = json_decode((string) ($call['function']['arguments'] ?? ''), true);
            $toolCalls[] = new LlmToolCall(
                (string) ($call['id'] ?? ''),
                $name,
                is_array($arguments) && ($arguments === [] || ! array_is_list($arguments)) ? $arguments : null,
            );
        }

        if ($content === null && $toolCalls === []) {
            throw new LlmUnavailable('The AI service answered nothing.');
        }

        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];

        return new LlmResponse(
            $content,
            $toolCalls,
            $finishReason,
            $finishReason === 'length',
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
            (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0),
            $message,
        );
    }
}
