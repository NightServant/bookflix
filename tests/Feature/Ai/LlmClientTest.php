<?php

use App\Ai\KillSwitch;
use App\Ai\LlmClient;
use App\Ai\LlmRequest;
use App\Ai\LlmUnavailable;
use App\Models\AiUsage;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config()->set('bookflix.ai', array_merge(config('bookflix.ai'), [
        'enabled' => true,
        'base_url' => 'https://llm.test/v1',
        'api_key' => 'sk-secret-key-123',
        'models' => ['fast' => 'tiny-model', 'smart' => 'big-model'],
        'json_mode' => 'json_object',
    ]));
    Sleep::fake();
});

function llmRequest(array $overrides = []): LlmRequest
{
    return new LlmRequest(...array_merge([
        'feature' => 'intent',
        'model' => 'fast',
        'system' => 'You parse searches.',
        'messages' => [['role' => 'user', 'content' => 'cozy fantasy']],
    ], $overrides));
}

function llmAnswer(array $message = ['content' => 'hello'], string $finish = 'stop', array $usage = ['prompt_tokens' => 10, 'completion_tokens' => 5]): array
{
    return ['choices' => [['message' => ['role' => 'assistant'] + $message, 'finish_reason' => $finish]], 'usage' => $usage];
}

function llm(): LlmClient
{
    return app(LlmClient::class);
}

it('sends nothing when disabled or unconfigured', function (string $key, mixed $value) {
    config()->set($key, $value);
    Http::fake();

    expect(fn () => llm()->complete(llmRequest()))->toThrow(LlmUnavailable::class);
    Http::assertNothingSent();
})->with([
    'disabled' => ['bookflix.ai.enabled', false],
    'no key' => ['bookflix.ai.api_key', ''],
    'no base url' => ['bookflix.ai.base_url', null],
    'no model' => ['bookflix.ai.models.fast', null],
]);

it('sends nothing when killed', function () {
    app(KillSwitch::class)->kill();
    Http::fake();

    expect(fn () => llm()->complete(llmRequest()))->toThrow(LlmUnavailable::class);
    Http::assertNothingSent();
});

it('stops on the kill switch and works again after resume', function () {
    Http::fake(['*' => Http::response(llmAnswer())]);
    $switch = app(KillSwitch::class);

    $switch->kill();
    expect($switch->active())->toBeTrue()
        ->and(fn () => llm()->complete(llmRequest()))->toThrow(LlmUnavailable::class);

    $switch->resume();
    expect($switch->active())->toBeFalse()
        ->and(llm()->complete(llmRequest())->text)->toBe('hello');
});

it('posts the system prompt first with the bearer key, the configured model and the feature token limit', function () {
    Http::fake(['*' => Http::response(llmAnswer())]);

    llm()->complete(llmRequest(['feature' => 'assistant', 'model' => 'smart']));

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://llm.test/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-secret-key-123')
            && $request['model'] === 'big-model'
            && $request['messages'][0] === ['role' => 'system', 'content' => 'You parse searches.']
            && $request['messages'][1]['content'] === 'cozy fantasy'
            && $request['max_tokens'] === 800
            && ! isset($request['response_format'])
            && ! isset($request['tools']);
    });
});

it('lets the request override max tokens and passes tools', function () {
    Http::fake(['*' => Http::response(llmAnswer())]);
    $tool = ['type' => 'function', 'function' => ['name' => 'search', 'parameters' => ['type' => 'object']]];

    llm()->complete(llmRequest(['maxTokens' => 42, 'tools' => [$tool]]));

    Http::assertSent(fn (Request $request) => $request['max_tokens'] === 42 && $request['tools'] === [$tool]);
});

it('adds response_format for json calls', function () {
    Http::fake(['*' => Http::response(llmAnswer())]);

    llm()->complete(llmRequest(['json' => true]));

    Http::assertSent(fn (Request $request) => $request['response_format'] === ['type' => 'json_object']);
});

it('leaves response_format out when json mode is none', function () {
    config()->set('bookflix.ai.json_mode', 'none');
    Http::fake(['*' => Http::response(llmAnswer())]);

    llm()->complete(llmRequest(['json' => true]));

    Http::assertSent(fn (Request $request) => ! isset($request['response_format']));
});

it('retries a 429 once after retry-after and then gives up', function () {
    Http::fakeSequence()
        ->push('', 429, ['Retry-After' => '30'])
        ->push('', 429, ['Retry-After' => '1']);

    expect(fn () => llm()->complete(llmRequest()))->toThrow(LlmUnavailable::class);

    Http::assertSentCount(2);
    Sleep::assertSequence([Sleep::for(5)->seconds()]);
});

it('succeeds when the retry after a 429 or 5xx works', function (int $status) {
    Http::fakeSequence()->push('', $status)->push(llmAnswer());

    expect(llm()->complete(llmRequest())->text)->toBe('hello');
    Http::assertSentCount(2);
})->with([429, 500, 503]);

it('gives up after a second 5xx', function () {
    Http::fakeSequence()->push('', 500)->push('', 502);

    expect(fn () => llm()->complete(llmRequest()))->toThrow(LlmUnavailable::class);
    Http::assertSentCount(2);
});

it('does not retry a 4xx', function () {
    Http::fake(['*' => Http::response('', 401)]);

    expect(fn () => llm()->complete(llmRequest()))->toThrow(LlmUnavailable::class);
    Http::assertSentCount(1);
});

it('retries a connection failure once, then throws', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(fn () => llm()->complete(llmRequest()))->toThrow(LlmUnavailable::class);
    Sleep::assertSleptTimes(1);
});

it('treats unusable answers as unavailable and records nothing', function (mixed $body) {
    Http::fake(['*' => Http::response($body)]);

    expect(fn () => llm()->complete(llmRequest()))->toThrow(LlmUnavailable::class);
    expect(AiUsage::count())->toBe(0);
})->with([
    'not json' => 'plain text, not json',
    'no choices' => [['choices' => []]],
    'content filter' => fn () => llmAnswer(['content' => 'x'], 'content_filter'),
    'empty content' => fn () => llmAnswer(['content' => '   ']),
    'null content, no tools' => fn () => llmAnswer(['content' => null]),
]);

it('parses tool calls with decoded arguments, null when the JSON is invalid', function () {
    Http::fake(['*' => Http::response(llmAnswer([
        'content' => null,
        'tool_calls' => [
            ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '{"q":"dune","limit":3}']],
            ['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{broken']],
            ['id' => 'c3', 'type' => 'function', 'function' => ['name' => 'noargs', 'arguments' => '{}']],
        ],
    ], 'tool_calls'))]);

    $response = llm()->complete(llmRequest());

    expect($response->text)->toBeNull()
        ->and($response->finishReason)->toBe('tool_calls')
        ->and($response->toolCalls)->toHaveCount(3)
        ->and($response->toolCalls[0]->id)->toBe('c1')
        ->and($response->toolCalls[0]->name)->toBe('search')
        ->and($response->toolCalls[0]->arguments)->toBe(['q' => 'dune', 'limit' => 3])
        ->and($response->toolCalls[1]->arguments)->toBeNull()
        ->and($response->toolCalls[2]->arguments)->toBe([])
        ->and($response->assistantMessage['tool_calls'])->toHaveCount(3);
});

it('flags a truncated answer', function () {
    Http::fake(['*' => Http::response(llmAnswer(['content' => 'cut off'], 'length'))]);

    $response = llm()->complete(llmRequest());

    expect($response->truncated)->toBeTrue()->and($response->text)->toBe('cut off');
});

it('records usage per user and feature including cached tokens', function () {
    $user = User::factory()->create();
    Http::fake(['*' => Http::response(llmAnswer(usage: [
        'prompt_tokens' => 120, 'completion_tokens' => 30, 'prompt_tokens_details' => ['cached_tokens' => 100],
    ]))]);

    $response = llm()->complete(llmRequest(['user' => $user, 'feature' => 'summary', 'model' => 'smart']));

    expect($response->inputTokens)->toBe(120)->and($response->outputTokens)->toBe(30)->and($response->cachedInputTokens)->toBe(100);

    $usage = AiUsage::sole();
    expect($usage->user_id)->toBe($user->id)
        ->and($usage->feature)->toBe('summary')
        ->and($usage->model)->toBe('big-model')
        ->and([$usage->input_tokens, $usage->output_tokens, $usage->cached_input_tokens])->toBe([120, 30, 100]);
});

it('keeps the api key out of exceptions and logs', function () {
    Log::spy();
    Http::fake(['*' => Http::response('boom', 500)]);

    try {
        llm()->complete(llmRequest());
    } catch (LlmUnavailable $exception) {
        expect($exception->getMessage())->not->toContain('sk-secret-key-123')
            ->and((string) $exception)->not->toContain('sk-secret-key-123');
    }

    Log::shouldNotHaveReceived('debug');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

it('logs payloads without the key only when log_payloads is on', function () {
    config()->set('bookflix.ai.log_payloads', true);
    Log::spy();
    Http::fake(['*' => Http::response(llmAnswer())]);

    llm()->complete(llmRequest());

    Log::shouldHaveReceived('debug')->twice();
    Log::shouldNotHaveReceived('debug', fn ($message, $context = []) => str_contains(json_encode($context), 'sk-secret-key-123'));
});
