<?php

use App\Ai\Assistant\AssistantRunner;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\Genre;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Database\Seeders\MediaTypeSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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
    $this->other = User::factory()->create();
    RateLimiter::clear('ai-assistant:'.$this->user->id);
    $this->court = Media::factory()->manga()->create(['title' => 'Crimson Court']);
    $this->court->genres()->attach(Genre::where('slug', 'dark-fantasy')->firstOrFail());
});

function historyAnswer(string $text = 'Try Crimson Court. Throne politics, all the way down.'): void
{
    $search = ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
        ['id' => 'call_0', 'type' => 'function', 'function' => ['name' => 'search_catalog', 'arguments' => json_encode(['query' => 'Crimson Court', 'limit' => 3])]],
    ]], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10]];
    $answer = ['choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10]];

    Http::fake(['*' => Http::sequence()->push($search)->push($answer)]);
}

function plainAnswer(string $text = 'Fine.'): void
{
    Http::fake(['*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10]])]);
}

/**
 * @return array<string, mixed>
 */
function lastRequestBody(): array
{
    return Http::recorded()->last()[0]->data();
}

function chatPage(User $user, AssistantConversation $chat)
{
    return Livewire::actingAs($user)->test('pages::assistant.index', ['conversation' => $chat->id]);
}

test('the first message creates a chat titled from it, and both messages are stored with the verified stories', function () {
    historyAnswer();

    Livewire::actingAs($this->user)->test('pages::assistant.index')->set('message', 'dark fantasy with politics')->call('send');

    $chat = $this->user->assistantConversations()->sole();
    [$question, $answer] = $chat->messages()->get()->all();

    expect($chat->title)->toBe('dark fantasy with politics')
        ->and($chat->last_message_at)->not->toBeNull()
        ->and($chat->archived_at)->toBeNull()
        ->and($question->only(['role', 'text', 'fixed']))->toBe(['role' => 'user', 'text' => 'dark fantasy with politics', 'fixed' => false])
        ->and($answer->role)->toBe('assistant')
        ->and($answer->text)->toBe('Try Crimson Court. Throne politics, all the way down.')
        ->and($answer->story_ids)->toBe([$this->court->id])
        ->and($answer->used_library)->toBeFalse();
});

test('the title is the first message on one line, cut to 60 characters', function () {
    plainAnswer();

    Livewire::actingAs($this->user)->test('pages::assistant.index')->set('message', "  something\n\n  with   gaps ".str_repeat('x', 100))->call('send');

    $title = $this->user->assistantConversations()->sole()->title;

    expect($title)->toStartWith('something with gaps xxx')->and(mb_strlen($title))->toBe(60);
});

test('the model gets the last ten stored messages as history', function () {
    plainAnswer();
    $chat = AssistantConversation::factory()->for($this->user)->create();

    foreach (range(1, 8) as $i) {
        $chat->messages()->create(['role' => 'user', 'text' => "u{$i}"]);
        $chat->messages()->create(['role' => 'assistant', 'text' => "a{$i}"]);
    }

    app(AssistantRunner::class)->reply($this->user, $chat, 'and now?');

    $sent = collect(lastRequestBody()['messages'])->skip(1)->values();

    expect($sent)->toHaveCount(11)
        ->and($sent[0])->toBe(['role' => 'user', 'content' => '<message>u4</message>'])
        ->and($sent[9])->toBe(['role' => 'assistant', 'content' => 'a8'])
        ->and($sent[10])->toBe(['role' => 'user', 'content' => '<message>and now?</message>']);
});

test('turns that used the library are left out of the history when the library or personalization is off', function (bool $useLibrary, bool $personalization, bool $sent) {
    plainAnswer();
    $this->user->profile->update(['personalization_enabled' => $personalization]);
    $chat = AssistantConversation::factory()->for($this->user)->create(['use_library' => $useLibrary]);
    $chat->messages()->create(['role' => 'user', 'text' => 'a plain question']);
    $chat->messages()->create(['role' => 'assistant', 'text' => 'a plain answer']);
    $chat->messages()->create(['role' => 'user', 'text' => 'what do I own?', 'used_library' => true]);
    $chat->messages()->create(['role' => 'assistant', 'text' => 'Secret Shelf Book was a pick.', 'used_library' => true]);

    app(AssistantRunner::class)->reply($this->user->fresh(), $chat, 'next?');

    $body = json_encode(lastRequestBody()['messages']);

    expect(str_contains($body, 'Secret Shelf Book'))->toBe($sent)
        ->and($body)->toContain('a plain answer');
})->with([
    'library off' => [false, true, false],
    'personalization off' => [true, false, false],
    'both on' => [true, true, true],
]);

test('a fixed line and the question it answered are saved and shown but never sent back to the model', function () {
    plainAnswer('Sure.');
    $chat = AssistantConversation::factory()->for($this->user)->create();
    $runner = app(AssistantRunner::class);

    $refused = $runner->reply($this->user, $chat, 'a lolicon story please');
    Http::assertNothingSent();
    $runner->reply($this->user, $chat, 'a normal question');

    $saved = $chat->messages()->get();
    $sent = json_encode(lastRequestBody()['messages']);

    expect($refused->fixed)->toBeTrue()
        ->and($saved)->toHaveCount(4)
        ->and($saved->pluck('fixed')->all())->toBe([true, true, false, false])
        ->and($sent)->not->toContain('lolicon')->not->toContain($refused->text)
        ->and($chat->history(true))->toBe([['role' => 'user', 'text' => 'a normal question'], ['role' => 'assistant', 'text' => 'Sure.']]);
});

test('an over-long message is refused and not saved', function () {
    Http::fake();
    $chat = AssistantConversation::factory()->for($this->user)->create();

    $reply = app(AssistantRunner::class)->reply($this->user, $chat, str_repeat('a', 2001));

    expect($reply->text)->toBe(AssistantRunner::TOO_LONG)->and($chat->messages()->count())->toBe(0);
    Http::assertNothingSent();
});

test('a reader can rename, archive, restore and delete their own chats', function () {
    $chat = AssistantConversation::factory()->for($this->user)->create(['title' => 'Old title']);
    $page = Livewire::actingAs($this->user)->test('pages::assistant.index');

    $page->call('rename', $chat->id, '  Better   title ');
    expect($chat->fresh()->title)->toBe('Better title');

    $page->call('archive', $chat->id)->assertNoRedirect();
    expect($chat->fresh()->archived_at)->not->toBeNull();
    $page->assertDontSee('Better title');

    $page->call('toggleArchived')->assertSee('Better title')->call('restore', $chat->id);
    expect($chat->fresh()->archived_at)->toBeNull();

    $page->call('toggleArchived')->assertSee('Better title')->call('delete', $chat->id)->assertDontSee('Better title');
    expect(AssistantConversation::count())->toBe(0);
});

test('a rename needs a title of at most 120 characters', function () {
    $chat = AssistantConversation::factory()->for($this->user)->create(['title' => 'Keep me']);
    $page = Livewire::actingAs($this->user)->test('pages::assistant.index');

    $page->call('rename', $chat->id, '   ')->assertHasErrors('title');
    $page->call('rename', $chat->id, str_repeat('a', 121))->assertHasErrors('title');
    expect($chat->fresh()->title)->toBe('Keep me');

    $page->call('rename', $chat->id, str_repeat('a', 120));
    expect(mb_strlen($chat->fresh()->title))->toBe(120);
});

test('archiving or deleting the open chat leaves its page; the other chats stay', function (string $action) {
    $chat = AssistantConversation::factory()->for($this->user)->create();
    $keep = AssistantConversation::factory()->for($this->user)->create(['title' => 'Stays put']);

    chatPage($this->user, $chat)->call($action, $chat->id)->assertRedirect(route('assistant'));
    expect($keep->fresh())->not->toBeNull();
})->with(['archive', 'delete']);

test('another reader cannot rename, archive, restore or delete a chat, or open it', function () {
    $chat = AssistantConversation::factory()->for($this->user)->create(['title' => 'Private plans']);
    $archived = AssistantConversation::factory()->for($this->user)->archived()->create(['title' => 'Quiet one']);

    foreach ([['rename', [$chat->id, 'Mine now']], ['archive', [$chat->id]], ['delete', [$chat->id]], ['restore', [$archived->id]], ['delete', [$archived->id]]] as [$action, $args]) {
        Livewire::actingAs($this->other)->test('pages::assistant.index')->call($action, ...$args)->assertNotFound();
    }

    expect($chat->fresh()->title)->toBe('Private plans')->and($chat->fresh()->archived_at)->toBeNull()
        ->and($archived->fresh()->archived_at)->not->toBeNull();

    $this->actingAs($this->other)->get(route('assistant.show', $chat->id))->assertNotFound();
    $this->actingAs($this->other)->get(route('assistant'))->assertDontSee('Private plans');
    Livewire::actingAs($this->other)->test('pages::assistant.index', ['conversation' => $chat->id])->assertNotFound();
});

test('the open chat id cannot be set from the browser, and a reply never lands in another reader\'s chat', function () {
    plainAnswer();
    $theirs = AssistantConversation::factory()->for($this->user)->create();
    $page = Livewire::actingAs($this->other)->test('pages::assistant.index');

    expect(fn () => $page->set('conversationId', $theirs->id))->toThrow(CannotUpdateLockedPropertyException::class);

    $page->set('message', 'hello')->call('send');

    expect($theirs->messages()->count())->toBe(0)->and($this->other->assistantConversations()->count())->toBe(1);
});

test('the chat page shows its own messages, its own list and marks the open chat', function () {
    $chat = AssistantConversation::factory()->for($this->user)->create(['title' => 'Dark fantasy picks']);
    $chat->messages()->create(['role' => 'user', 'text' => 'something dark']);
    $chat->messages()->create(['role' => 'assistant', 'text' => 'Try Crimson Court.', 'story_ids' => [$this->court->id]]);
    AssistantConversation::factory()->for($this->user)->create(['title' => 'A second chat']);
    AssistantConversation::factory()->for($this->other)->create(['title' => 'Not yours']);

    $html = $this->actingAs($this->user)->get(route('assistant.show', $chat->id))->assertOk()
        ->assertSee('something dark')->assertSee('Try Crimson Court.')->assertSee('A second chat')->assertDontSee('Not yours')
        ->assertSeeHtml('href="'.route('stories.show', $this->court->slug).'"')
        ->getContent();

    expect(htmlQuery($html, '//aside//a[@aria-current="page"]'))->toHaveCount(1);
});

test('archived chats are hidden from the list and shown in the archived view', function () {
    AssistantConversation::factory()->for($this->user)->create(['title' => 'Live chat']);
    AssistantConversation::factory()->for($this->user)->archived()->create(['title' => 'Shelved chat']);

    $this->actingAs($this->user)->get(route('assistant'))->assertSee('Live chat')->assertDontSee('Shelved chat');

    Livewire::actingAs($this->user)->test('pages::assistant.index')
        ->assertDontSee('Shelved chat')
        ->call('toggleArchived')
        ->assertSee('Shelved chat')->assertDontSee('Live chat')->assertSee('Restore');

    $this->actingAs($this->user)->get(route('assistant.show', AssistantConversation::archived()->sole()->id))->assertNotFound();
});

test('chats are grouped Today, Previous 7 days and Older by their last message', function () {
    $this->travelTo(now()->setTime(15, 0));
    $make = fn (string $title, $at) => AssistantConversation::factory()->for($this->user)->create(['title' => $title, 'last_message_at' => $at]);
    $make('Chat this morning', now()->setTime(8, 0));
    $make('Chat yesterday', now()->subDay());
    $make('Chat six days ago', now()->subDays(6));
    $make('Chat last month', now()->subDays(30));

    $groups = Livewire::actingAs($this->user)->test('pages::assistant.index')->instance()->groups;

    expect(array_keys($groups))->toBe(['Today', 'Previous 7 days', 'Older'])
        ->and($groups['Today']->pluck('title')->all())->toBe(['Chat this morning'])
        ->and($groups['Previous 7 days']->pluck('title')->all())->toBe(['Chat yesterday', 'Chat six days ago'])
        ->and($groups['Older']->pluck('title')->all())->toBe(['Chat last month']);

    $this->travelBack();
});

test('with AI off the page is the notice alone: no sidebar and no chat queries', function () {
    config()->set('bookflix.ai.enabled', false);
    $chat = AssistantConversation::factory()->for($this->user)->create(['title' => 'Hidden while off']);
    DB::enableQueryLog();

    $this->actingAs($this->user)->get(route('assistant'))->assertOk()
        ->assertSee('The assistant is switched off.')->assertDontSee('Hidden while off')->assertDontSee('Chat history')->assertDontSee('New chat');
    $this->actingAs($this->user)->get(route('assistant.show', $chat->id))->assertOk()->assertSee('The assistant is switched off.');

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'assistant_')))->toBeEmpty();
});

test('a guest is sent to sign in', function () {
    $this->get(route('assistant.show', AssistantConversation::factory()->create()->id))->assertRedirect(route('login'));
});

test('deleting a chat removes its messages, and purging an account removes all its chats', function () {
    $user = User::factory()->create(['deactivated_at' => now()->subDays(31)]);
    $chat = AssistantConversation::factory()->for($user)->create();
    $chat->messages()->create(['role' => 'user', 'text' => 'hello']);
    $kept = AssistantConversation::factory()->for($this->user)->create();
    $kept->messages()->create(['role' => 'user', 'text' => 'still here']);

    $this->artisan('bookflix:purge-deactivated-accounts')->assertSuccessful();

    expect(User::find($user->id))->toBeNull()
        ->and(AssistantConversation::find($chat->id))->toBeNull()
        ->and(AssistantMessage::where('assistant_conversation_id', $chat->id)->count())->toBe(0)
        ->and($kept->fresh())->not->toBeNull()
        ->and($kept->messages()->count())->toBe(1);

    Livewire::actingAs($this->user)->test('pages::assistant.index')->call('delete', $kept->id);
    expect(AssistantMessage::count())->toBe(0);
});

test('the rename answers with the reason when it refuses a title', function () {
    $chat = AssistantConversation::factory()->for($this->user)->create(['title' => 'Keep me']);

    Livewire::actingAs($this->user)->test('pages::assistant.index')
        ->call('rename', $chat->id, str_repeat('a', 121))
        ->assertHasErrors('title')
        ->assertReturned(fn ($reason): bool => is_string($reason) && str_contains($reason, '120'));
    expect($chat->fresh()->title)->toBe('Keep me');
});

test('a fallback reply creates no chat and stores no messages, and the text stays in the field', function () {
    Http::fake(['*' => Http::response('down', 503)]);

    Livewire::actingAs($this->user)->test('pages::assistant.index')->set('message', 'anything')->call('send')
        ->assertHasErrors('message')->assertSet('message', 'anything')->assertNoRedirect();

    expect(AssistantConversation::count())->toBe(0)->and(AssistantMessage::count())->toBe(0);

    $chat = AssistantConversation::factory()->for($this->user)->create();
    chatPage($this->user, $chat)->set('message', 'again')->call('send')->assertHasErrors('message');
    expect($chat->messages()->count())->toBe(0);
});

test('once the limit is reached nothing more is written, and refused or blocked texts count toward it', function () {
    plainAnswer();
    $page = Livewire::actingAs($this->user)->test('pages::assistant.index');

    foreach (range(1, 12) as $i) {
        $page->set('message', "a lolicon story {$i}")->call('send');
    }
    $chat = AssistantConversation::query()->latest('created_at')->firstOrFail();
    $page = chatPage($this->user, $chat);

    foreach (range(1, 13) as $i) {
        $page->set('message', "lolicon again {$i}")->call('send');
    }

    $afterLimit = [AssistantConversation::count(), AssistantMessage::count()];

    foreach (range(1, 10) as $i) {
        $page->set('message', "hello {$i}")->call('send');
        Livewire::actingAs($this->user)->test('pages::assistant.index')->set('message', "fresh {$i}")->call('send');
    }

    expect([AssistantConversation::count(), AssistantMessage::count()])->toBe($afterLimit)
        ->and(AssistantMessage::where('role', 'user')->count())->toBe(20);
    Http::assertNothingSent();
});
