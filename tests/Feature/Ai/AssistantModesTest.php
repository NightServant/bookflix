<?php

use App\Ai\Assistant\AssistantMode;
use App\Ai\Assistant\AssistantRunner;
use App\Models\AssistantConversation;
use App\Models\Genre;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Database\Seeders\MediaTypeSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([MediaTypeSeeder::class, TaxonomySeeder::class, DeweySeeder::class]);
    Cache::flush();
    Sleep::fake();
    config()->set('bookflix.ai', array_merge(config('bookflix.ai'), [
        'enabled' => true,
        'base_url' => 'https://llm.test/v1',
        'api_key' => 'sk-test',
        'models' => ['fast' => 'tiny-model', 'smart' => 'big-model', 'vision' => null],
        'log_payloads' => false,
    ]));
    $this->user = User::factory()->create();
    RateLimiter::clear('ai-assistant:'.$this->user->id);
    $this->court = Media::factory()->manga()->create(['title' => 'Crimson Court']);
    $this->court->genres()->attach(Genre::where('slug', 'dark-fantasy')->firstOrFail());
});

function modeAnswer(string $text = 'Try Crimson Court. Throne politics.'): array
{
    return ['choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10]];
}

function modeSearch(string $tool = 'search_catalog', array $arguments = ['query' => 'Crimson Court']): array
{
    return ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
        ['id' => 'call_0', 'type' => 'function', 'function' => ['name' => $tool, 'arguments' => json_encode($arguments)]],
    ]], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10]];
}

function modeScript(array ...$replies): void
{
    $sequence = Http::sequence();

    foreach ($replies as $reply) {
        $sequence->push($reply);
    }

    Http::fake(['*' => $sequence]);
}

/**
 * @return list<array<string, mixed>>
 */
function modeBodies(): array
{
    return Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->values()->all();
}

function modeAsk(User $user, string $mode, bool $library = false): array
{
    $chat = AssistantConversation::factory()->for($user)->create(['use_library' => $library]);
    app(AssistantRunner::class)->reply($user, $chat, 'something dark', $mode);

    return modeBodies();
}

it('offers each task its own tools', function (string $mode, bool $library, array $tools) {
    modeScript(modeAnswer());

    $body = modeAsk($this->user, $mode, $library)[0];

    expect(array_column(array_column($body['tools'], 'function'), 'name'))->toBe($tools);
})->with([
    'find: all tools' => ['find:quick', false, ['search_catalog', 'get_story', 'explore_subject']],
    'find with the library' => ['find:quick', true, ['search_catalog', 'get_story', 'explore_subject', 'get_recommendations']],
    'ask about a story' => ['ask:quick', true, ['search_catalog', 'get_story']],
    'plan my reading' => ['plan:quick', false, ['search_catalog', 'explore_subject']],
    'plan with the library' => ['plan:thorough', true, ['search_catalog', 'explore_subject', 'get_recommendations']],
]);

it('sends the base prompt first and byte-stable, then the task block', function () {
    $base = file_get_contents(app_path('Ai/Prompts/assistant.md'));
    modeScript(modeAnswer(), modeAnswer(), modeAnswer());

    $systems = [];

    foreach (['find:quick', 'ask:quick', 'plan:quick'] as $mode) {
        $systems[] = modeAsk($this->user, $mode)[array_key_last(modeBodies())]['messages'][0]['content'];
        Http::fake(['*' => Http::response(modeAnswer())]);
    }

    expect($systems[0])->toStartWith($base."\n\nTask: find stories.")
        ->and($systems[1])->toStartWith($base."\n\nTask: answer a question about one story.")
        ->and($systems[2])->toStartWith($base."\n\nTask: plan");
});

it('does not run a tool its task does not offer', function () {
    modeScript(modeSearch('explore_subject', ['code' => '813']), modeAnswer());

    $bodies = modeAsk($this->user, 'ask:quick');
    $tool = collect($bodies[1]['messages'])->firstWhere('role', 'tool');

    expect($tool['content'])->toContain('Unknown tool.');
});

it('answers Quick with the fast model and stops after two tool turns with a last call that may not use tools', function () {
    modeScript(modeSearch(), modeSearch(), modeAnswer());

    $bodies = modeAsk($this->user, 'find:quick');

    expect($bodies)->toHaveCount(3)
        ->and(array_column($bodies, 'model'))->toBe(['tiny-model', 'tiny-model', 'tiny-model'])
        ->and($bodies[1])->not->toHaveKey('tool_choice')
        ->and($bodies[2]['tool_choice'])->toBe('none');
});

it('answers Thorough with the smart model and up to four tool turns', function () {
    modeScript(modeSearch(), modeSearch(), modeSearch(), modeSearch(), modeAnswer());

    $bodies = modeAsk($this->user, 'find:thorough');

    expect($bodies)->toHaveCount(5)
        ->and(array_unique(array_column($bodies, 'model')))->toBe(['big-model'])
        ->and($bodies[3])->not->toHaveKey('tool_choice')
        ->and($bodies[4]['tool_choice'])->toBe('none');
});

it('saves the mode on the answer, and an unknown mode becomes the default', function (?string $sent, string $stored) {
    modeScript(modeAnswer());
    $chat = AssistantConversation::factory()->for($this->user)->create();

    app(AssistantRunner::class)->reply($this->user, $chat, 'something dark', $sent);

    expect($chat->messages()->where('role', 'assistant')->value('mode'))->toBe($stored);
})->with([
    ['plan:thorough', 'plan:thorough'],
    ['ask:quick', 'ask:quick'],
    ['nonsense', 'find:quick'],
    ['find:forever', 'find:quick'],
    ['plan:bogus', 'plan:quick'],
    [null, 'find:quick'],
]);

it('parses a mode key', function () {
    expect(AssistantMode::parse('plan:thorough')->key())->toBe('plan:thorough')
        ->and(AssistantMode::parse("find:quick\0")->key())->toBe('find:quick')
        ->and(AssistantMode::parse('plan:thorough')->model())->toBe('smart')
        ->and(AssistantMode::parse('plan:quick')->maxToolTurns())->toBe(2);
});

test('the page shows the task picker and the depth switch, and sends the chosen mode', function () {
    modeScript(modeAnswer());

    $page = Livewire::actingAs($this->user)->test('pages::assistant.index')
        ->assertSeeHtml('role="radiogroup"')
        ->assertSee('Find stories')->assertSee('Ask about a story')->assertSee('Plan my reading')
        ->assertSeeHtml('aria-pressed="false"')
        ->assertSet('task', 'find')->assertSet('depth', 'quick')
        ->set('task', 'plan')
        ->call('toggleDepth')
        ->assertSeeHtml('aria-pressed="true"')
        ->set('message', 'a reading plan')
        ->call('send');

    $chat = $this->user->assistantConversations()->sole();

    expect($chat->messages()->where('role', 'assistant')->value('mode'))->toBe('plan:thorough')
        ->and(modeBodies()[0]['model'])->toBe('big-model');

    // The chat's own page starts the pickers where the last answer was.
    Livewire::actingAs($this->user)->test('pages::assistant.index', ['conversation' => $chat->id])
        ->assertSet('task', 'plan')->assertSet('depth', 'thorough');
});

test('a mode the client invents is the default', function () {
    modeScript(modeAnswer());

    Livewire::actingAs($this->user)->test('pages::assistant.index')
        ->set('task', 'hack')->set('depth', 'maximum')->set('message', 'anything')->call('send');

    expect($this->user->assistantConversations()->sole()->messages()->where('role', 'assistant')->value('mode'))->toBe('find:quick');
});
