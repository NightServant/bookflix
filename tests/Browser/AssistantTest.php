<?php

use App\Models\Genre;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Database\Seeders\MediaTypeSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Support\Facades\Http;

/*
 * The assistant page in a real browser: asking by keyboard, the answer and its cover cards, the keyboard order, the audit, and the phone.
 * The model is faked (a search, then an answer), so nothing leaves the machine.
 */

beforeEach(function () {
    $this->seed([MediaTypeSeeder::class, TaxonomySeeder::class, DeweySeeder::class]);
    config()->set('bookflix.ai', array_merge(config('bookflix.ai'), [
        'enabled' => true, 'base_url' => 'https://llm.test/v1', 'api_key' => 'sk-test',
        'models' => ['fast' => 'tiny-model', 'smart' => 'big-model'], 'log_payloads' => false,
    ]));

    $court = Media::factory()->manga()->create(['title' => 'Crimson Court', 'cover_url' => '/favicon.svg']);
    $court->genres()->attach(Genre::where('slug', 'dark-fantasy')->firstOrFail());

    $search = ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
        ['id' => 'call_0', 'type' => 'function', 'function' => ['name' => 'search_catalog', 'arguments' => json_encode(['query' => 'Crimson Court', 'limit' => 3])]],
    ]], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10]];
    $answer = ['choices' => [['message' => ['role' => 'assistant', 'content' => 'Try Crimson Court. It is dark fantasy built on throne politics.'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10]];
    Http::fake(['*' => Http::sequence()->push($search)->push($answer)]);

    $this->actingAs(User::factory()->create());
    $this->get(route('assistant'))->assertOk();

    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900], 'reducedMotion' => 'reduce'];
    $this->phone = ['viewport' => ['width' => 375, 'height' => 812], 'reducedMotion' => 'reduce'];
});

test('a question typed and sent with Enter shows the answer and a cover card, and Escape never clears the input', function () {
    $page = visit(route('assistant'), $this->desktop)->wait(1);

    $page->assertSee('What are you in the mood for?')
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'assistant-1440-empty')
        ->type('#assistant-message', 'dark fantasy with politics')
        ->keys('#assistant-message', 'Escape')
        ->assertValue('#assistant-message', 'dark fantasy with politics')
        ->keys('#assistant-message', 'Enter')->wait(2)
        ->assertSee('dark fantasy with politics')
        ->assertSee('Try Crimson Court. It is dark fantasy built on throne politics.')
        ->assertPathBeginsWith('/assistant/')
        ->assertScript('document.querySelectorAll("[aria-live=polite] ul a[href*=\"/stories/\"]").length', 1)
        ->assertValue('#assistant-message', '')
        ->assertScript('document.activeElement.id === "assistant-message"', true)
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'assistant-1440-answer');

    $page->click('aside a:has-text("New chat")')->wait(1)->assertPathIs('/assistant')->assertSee('What are you in the mood for?')->assertDontSee('Try Crimson Court.');
});

test('every control is reachable by keyboard in a sensible order, with a visible focus ring', function () {
    $page = visit(route('assistant'), $this->desktop)->wait(1);
    $label = '(document.activeElement.id || document.activeElement.textContent.trim() || document.activeElement.getAttribute("role") || document.activeElement.tagName)';

    $page->script('document.querySelector("main").focus()');

    $order = [];

    foreach (range(1, 14) as $step) {
        $page->keys(':root', 'Tab');
        $order[] = $page->script($label);

        if ($page->script('document.activeElement.id') === 'assistant-message') {
            $page->assertScript('getComputedStyle(document.activeElement).boxShadow !== "none"', true);
        }
    }

    $inputAt = array_search('assistant-message', $order, true);

    expect($inputAt)->not->toBeFalse()
        ->and($order[$inputAt + 1])->toBe('Send')
        ->and($order[$inputAt + 2])->toBe('switch')
        ->and($order[$inputAt + 3])->toBe('What we send')
        ->and(array_slice($order, 0, $inputAt))->toContain('Something like Frieren', 'A short classic for a train ride');
});

test('a suggestion chip asks by itself', function () {
    visit(route('assistant'), $this->desktop)->wait(1)
        ->click('Something like Frieren')->wait(2)
        ->assertSee('Try Crimson Court.')
        ->assertDontSee('What are you in the mood for?');
});

test('the 2,000 character counter appears near the limit and a message that is too long is refused', function () {
    visit(route('assistant'), $this->desktop)->wait(1)
        ->assertDontSee('/ 2,000')
        ->type('#assistant-message', str_repeat('a', 1900))
        ->assertSee('1,900 / 2,000')
        ->type('#assistant-message', str_repeat('a', 2050))
        ->keys('#assistant-message', 'Enter')->wait(1.5)
        ->assertSee('Please keep it under 2,000 characters.')
        ->assertScript('document.getElementById("assistant-message").value.length', 2050);
});

test('on a phone nothing scrolls sideways and the whole bottom bar fits', function () {
    $page = visit(route('assistant'), $this->phone)->wait(1);

    $page->assertSee('What are you in the mood for?')
        ->screenshot(filename: 'assistant-375-empty')
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertScript('document.querySelectorAll("nav[aria-label=Primary] a").length', 5)
        ->assertScript('Array.from(document.querySelectorAll("nav[aria-label=Primary] a")).every((a) => { const r = a.getBoundingClientRect(); return r.left >= 0 && r.right <= document.documentElement.clientWidth && a.scrollWidth <= a.clientWidth; })', true)
        ->assertScript('Array.from(document.querySelectorAll("nav[aria-label=Primary] a span")).every((s) => s.scrollWidth <= s.clientWidth + 1)', true)
        ->assertScript('document.getElementById("assistant-message").getBoundingClientRect().height >= 44', true)
        ->type('#assistant-message', 'dark fantasy with politics')
        ->keys('#assistant-message', 'Enter')->wait(2)
        ->assertSee('Try Crimson Court.')
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'assistant-375-answer')
        ->script('window.scrollTo(0, 0)');

    // The composer sticks to the viewport just above the bottom bar, never under it.
    $page->assertScript('(() => { const bar = document.querySelector("nav[aria-label=Primary]").getBoundingClientRect(); const box = document.getElementById("assistant-message").closest(".sticky").getBoundingClientRect(); return box.bottom <= bar.top + 1 && bar.top - box.bottom < 8; })()', true);
});

test('on Home the floating Ask button sits above the phone bottom bar and opens the assistant; on a desktop it is hidden', function () {
    $fab = 'document.querySelector("[data-test=ask-fab]")';

    $page = visit(route('home'), $this->phone)->wait(1.5);
    $page->assertScript("(() => { const r = {$fab}.getBoundingClientRect(); return r.width >= 44 && r.height >= 44 && r.width > 0; })()", true)
        ->assertScript("(() => { const f = {$fab}.getBoundingClientRect(); const bar = document.querySelector('nav[aria-label=Primary]').getBoundingClientRect(); return f.bottom <= bar.top && f.right <= window.innerWidth; })()", true)
        ->click('[data-test=ask-fab]')->wait(1.5)
        ->assertPathIs('/assistant');

    visit(route('home'), $this->desktop)->wait(1.5)
        ->assertScript("{$fab}.getBoundingClientRect().width", 0);
});
