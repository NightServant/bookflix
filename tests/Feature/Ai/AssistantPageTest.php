<?php

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
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([MediaTypeSeeder::class, TaxonomySeeder::class, DeweySeeder::class]);
    Cache::flush();
    Sleep::fake();
    config()->set('bookflix.ai', array_merge(config('bookflix.ai'), [
        'enabled' => true,
        'base_url' => 'https://llm.test/v1',
        'api_key' => 'sk-test',
        'models' => ['fast' => 'tiny-model', 'smart' => 'big-model'],
        'log_payloads' => false,
    ]));
    $this->user = User::factory()->create();
    RateLimiter::clear('ai-assistant:'.$this->user->id);
    $this->court = Media::factory()->manga()->create(['title' => 'Crimson Court']);
    $this->court->genres()->attach(Genre::where('slug', 'dark-fantasy')->firstOrFail());
});

/**
 * Script the model: a search for Crimson Court, then an answer that names it.
 */
function scriptCourtAnswer(string $answer = 'Try Crimson Court. Throne politics, all the way down.'): void
{
    $search = ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
        ['id' => 'call_0', 'type' => 'function', 'function' => ['name' => 'search_catalog', 'arguments' => json_encode(['query' => 'Crimson Court', 'limit' => 3])]],
    ]], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10]];
    $text = ['choices' => [['message' => ['role' => 'assistant', 'content' => $answer], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10]];

    Http::fake(['*' => Http::sequence()->push($search)->push($text)]);
}

test('with AI off the page is one line and a link to Search, and neither nav has Ask', function () {
    config()->set('bookflix.ai.enabled', false);

    $response = $this->actingAs($this->user)->get(route('assistant'))->assertOk();
    $navs = primaryNavs($response->getContent());

    $response->assertSee('The assistant is switched off.')
        ->assertSee('href="'.route('search').'"', false)
        ->assertDontSee('assistant-message', false);

    expect($navs['header'])->not->toContain('Ask')->and($navs['bottom'])->not->toContain('Ask');
    $this->actingAs($this->user)->get(route('home'))->assertOk()->assertDontSee('ask-fab', false)->assertDontSee('Ask Bookflix');
});

test('with AI on, Ask is in the header but not in the phone bottom bar, and Home has the floating button', function () {
    $navs = primaryNavs($this->actingAs($this->user)->get(route('home'))->assertOk()->getContent());
    $labels = fn (string $nav) => collect(htmlQuery($nav, '//a'))->map(fn (DOMElement $link): string => trim($link->textContent))->all();

    expect($labels($navs['header']))->toBe(['Home', 'Discover', 'Ask', 'Library', 'Profile'])
        ->and($labels($navs['bottom']))->toBe(['Home', 'Discover', 'Search', 'Library', 'Profile']);

    $home = $this->actingAs($this->user)->get(route('home'))->assertOk();
    $fab = htmlQuery($home->getContent(), '//a[@data-test="ask-fab"]');

    expect($fab)->toHaveCount(1)
        ->and($fab[0]->getAttribute('href'))->toBe(route('assistant'))
        ->and($fab[0]->getAttribute('aria-label'))->toBe('Ask Bookflix');
});

test('Ask is the current item on the assistant page', function () {
    $navs = primaryNavs($this->actingAs($this->user)->get(route('assistant'))->assertOk()->getContent());

    expect($navs['header'])->toMatch('#<a\s+href="'.preg_quote(route('assistant'), '#').'"[^>]*data-current#');
});

/**
 * The saved chat the first message created, opened as its own page.
 */
function openedChat(User $user): Testable
{
    return Livewire::actingAs($user)->test('pages::assistant.index', ['conversation' => $user->assistantConversations()->sole()->id]);
}

test('a first message creates the chat and sends the reader to its page, where the reply and only the cards of verified stories show', function () {
    $decoy = Media::factory()->manga()->create(['title' => 'Unmentioned Decoy']);
    scriptCourtAnswer();

    $new = Livewire::actingAs($this->user)->test('pages::assistant.index')
        ->assertSee('What are you in the mood for?')
        ->set('message', 'dark fantasy with politics')
        ->call('send');
    $chat = $this->user->assistantConversations()->sole();

    $new->assertRedirect(route('assistant.show', $chat->id));

    openedChat($this->user)
        ->assertSee('dark fantasy with politics')
        ->assertSee('Try Crimson Court. Throne politics, all the way down.')
        ->assertSeeHtml('href="'.route('stories.show', $this->court->slug).'"')
        ->assertDontSeeHtml('href="'.route('stories.show', $decoy->slug).'"')
        ->assertDontSee('What are you in the mood for?');
});

test('a second message in the open chat shows the answer on the same page', function () {
    scriptCourtAnswer();
    $chat = AssistantConversation::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)->test('pages::assistant.index', ['conversation' => $chat->id])
        ->set('message', 'dark fantasy with politics')
        ->call('send')
        ->assertNoRedirect()
        ->assertSee('Try Crimson Court. Throne politics, all the way down.')
        ->assertSet('message', '');

    expect($chat->messages()->pluck('role')->all())->toBe(['user', 'assistant']);
});

test('the model\'s text is shown as plain text', function () {
    scriptCourtAnswer('Try Crimson Court <script>alert(1)</script>.');

    Livewire::actingAs($this->user)->test('pages::assistant.index')->set('message', 'anything')->call('send');

    expect(openedChat($this->user)->html())->not->toContain('<script>alert(1)');
});

test('a suggestion chip sends its own text', function () {
    scriptCourtAnswer();

    Livewire::actingAs($this->user)->test('pages::assistant.index')->call('ask', 'Something like Frieren')->assertRedirect();

    openedChat($this->user)->assertSee('Something like Frieren')->assertSee('Try Crimson Court.');
    Http::assertSentCount(2);
});

test('a message over 2,000 characters is refused with a message and nothing is sent', function () {
    Http::fake();

    Livewire::actingAs($this->user)->test('pages::assistant.index')
        ->set('message', str_repeat('a', 2001))
        ->call('send')
        ->assertHasErrors(['message' => 'max'])
        ->assertSee('Please keep it under 2,000 characters.');

    Http::assertNothingSent();
});

test('an unavailable assistant shows its line and a link to Search', function () {
    Http::fake(['*' => Http::response('', 500)]);

    Livewire::actingAs($this->user)->test('pages::assistant.index')->set('message', 'anything')->call('send')
        ->assertSee("The assistant isn't available right now.")
        ->assertSeeHtml('href="'.route('search', ['q' => 'anything']).'"')
        ->assertSee('Try Search instead');

    expect($this->user->assistantConversations()->count())->toBe(0);
});

test('Use my library is offered only while personalization is on, and is off by default', function () {
    Livewire::actingAs($this->user)->test('pages::assistant.index')->assertSee('Use my library')->assertSet('useLibrary', false);

    $this->user->profile->update(['personalization_enabled' => false]);

    Livewire::actingAs($this->user->fresh())->test('pages::assistant.index')->assertDontSee('Use my library');
});

test('the library switch is saved with the chat and a new chat starts with it off', function () {
    scriptCourtAnswer();

    Livewire::actingAs($this->user)->test('pages::assistant.index')->set('useLibrary', true)->set('message', 'anything')->call('send');
    $chat = $this->user->assistantConversations()->sole();

    expect($chat->use_library)->toBeTrue();

    openedChat($this->user)->assertSet('useLibrary', true)->set('useLibrary', false);
    expect($chat->fresh()->use_library)->toBeFalse();

    Livewire::actingAs($this->user)->test('pages::assistant.index')->assertSet('useLibrary', false);
});

test('the "What we send" link goes to the AI section of the privacy settings', function () {
    $this->actingAs($this->user)->get(route('assistant'))
        ->assertSee('Answers come from an outside AI service.')
        ->assertSee('href="'.route('privacy.edit').'#ai"', false);

    expect(route('privacy.edit'))->toEndWith('/settings/privacy');
});

test('an admin is sent to the dashboard like on every other reader page', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('assistant'))->assertRedirect(route('admin.index'));
});

test('a guest is sent to sign in', function () {
    $this->get(route('assistant'))->assertRedirect(route('login'));
});
