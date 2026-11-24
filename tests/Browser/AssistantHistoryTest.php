<?php

use App\Models\AssistantConversation;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Database\Seeders\MediaTypeSeeder;
use Database\Seeders\TaxonomySeeder;

/*
 * The assistant's saved chats in a real browser: the desktop sidebar (list, the ⋯ menu and rename by keyboard, delete asks first), the
 * phone's History drawer (opens, Escape closes, focus comes back) and the audit. No model is called: the chats are stored rows.
 */

beforeEach(function () {
    $this->seed([MediaTypeSeeder::class, TaxonomySeeder::class, DeweySeeder::class]);
    config()->set('bookflix.ai', array_merge(config('bookflix.ai'), [
        'enabled' => true, 'base_url' => 'https://llm.test/v1', 'api_key' => 'sk-test',
        'models' => ['fast' => 'tiny-model', 'smart' => 'big-model'], 'log_payloads' => false,
    ]));

    $this->user = User::factory()->create();
    $court = Media::factory()->manga()->create(['title' => 'Crimson Court', 'cover_url' => '/favicon.svg']);

    $make = fn (string $title, $at, array $extra = []) => AssistantConversation::factory()->for($this->user)->create(['title' => $title, 'last_message_at' => $at] + $extra);
    $this->open = $make('Dark fantasy with political intrigue', now()->subMinutes(5));
    $this->open->messages()->create(['role' => 'user', 'text' => 'dark fantasy with political intrigue']);
    $this->open->messages()->create(['role' => 'assistant', 'text' => 'Try Crimson Court. It is built on throne politics.', 'story_ids' => [$court->id]]);
    $make('A short classic for a train ride', now()->subHours(2));
    $make('Cozy mysteries set in small towns', now()->subDays(3));
    $make('Space opera with found family', now()->subDays(12));
    $make('Hard sci-fi about first contact and the long wait', now()->subDays(40));
    $make('Shelved idea', now()->subDays(50), ['archived_at' => now()->subDay()]);

    $this->actingAs($this->user);
    $this->get(route('assistant'))->assertOk();

    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900], 'reducedMotion' => 'reduce'];
    $this->phone = ['viewport' => ['width' => 375, 'height' => 812], 'reducedMotion' => 'reduce'];
});

test('the desktop sidebar lists the chats by group, marks the open one and keeps the chat column', function () {
    $page = visit(route('assistant.show', $this->open->id), $this->desktop)->wait(1.5);

    $page->assertSee('Today')->assertSee('Previous 7 days')->assertSee('Older')
        ->assertSee('Cozy mysteries set in small towns')->assertDontSee('Shelved idea')
        ->assertSee('Try Crimson Court. It is built on throne politics.')
        ->assertScript('document.querySelectorAll("aside a[href*=\"/assistant/\"]").length', 5)
        ->assertScript('document.querySelector("aside a[aria-current=page]").textContent.trim()', 'Dark fantasy with political intrigue')
        ->assertScript('Math.round(document.querySelector("aside").getBoundingClientRect().width)', 288)
        ->assertScript('Math.round(document.querySelector("aside").getBoundingClientRect().left)', 0)
        ->assertScript('Math.round(document.getElementById("assistant-message").closest("form").getBoundingClientRect().width) <= 880', true)
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertScript('Array.from(document.querySelectorAll("aside a, aside button")).filter((el) => el.getClientRects().length > 0 && el.getBoundingClientRect().height < 43.5).map((el) => el.outerHTML.slice(0, 120) + el.getBoundingClientRect().height)', [])
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'assistant-history-1440');
});

test('the ⋯ menu opens by keyboard, Rename edits in place, Enter saves and Escape cancels', function () {
    $page = visit(route('assistant'), $this->desktop)->wait(1.5);
    $trigger = 'aside li:has(a[href$="'.$this->open->id.'"]) button[aria-label^="Options for"]';

    // Escape puts the old title back and nothing is saved.
    $page->script("document.querySelector('{$trigger}').focus()");
    $page->keys($trigger, 'Enter')->wait(0.5)
        ->assertScript('document.querySelectorAll("aside ui-menu [role=menuitem], aside ui-menu ui-menu-item").length >= 3', true);
    $page->assertScript('document.activeElement.getAttribute("role")', 'menu')
        ->keys(':root', 'ArrowDown')->wait(0.2)
        ->assertScript('document.activeElement.textContent.trim()', 'Rename')
        ->keys(':root', 'Enter')->wait(0.6)
        ->assertScript('document.activeElement.tagName === "INPUT" && document.activeElement.value === "Dark fantasy with political intrigue"', true)
        ->keys('aside input[id^=rename-side]:visible', 'End')
        ->keys('aside input[id^=rename-side]:visible', 'x')
        ->keys('aside input[id^=rename-side]:visible', 'Escape')->wait(0.3)
        ->assertScript('document.activeElement.textContent.trim()', 'Dark fantasy with political intrigue')
        ->assertDontSee('political intrigue x');
    expect($this->open->fresh()->title)->toBe('Dark fantasy with political intrigue');

    // Enter saves the new title.
    $page->script("document.querySelector('{$trigger}').focus()");
    $page->keys($trigger, 'Enter')->wait(0.5)->click('ui-menu button:has-text("Rename"):visible')->wait(0.6)
        ->keys('aside input[id^=rename-side]:visible', 'Control+a')
        ->type('aside input[id^=rename-side]:visible', 'Politics and thrones')
        ->keys('aside input[id^=rename-side]:visible', 'Enter')->wait(1.2)
        ->assertSee('Politics and thrones');

    expect($this->open->fresh()->title)->toBe('Politics and thrones');
});

test('Delete asks first and removes the chat only when confirmed; Archive moves it to the archived view', function () {
    $page = visit(route('assistant'), $this->desktop)->wait(1.5);
    $trigger = 'aside li:has(a:has-text("Cozy mysteries")) button[aria-label^="Options for"]';

    $page->script('window.__asked = []; window.confirm = (message) => { window.__asked.push(message); return window.__answer; }; window.__answer = false;');
    $page->click($trigger)->wait(0.4)->click('ui-menu button:has-text("Delete"):visible')->wait(1)
        ->assertSee('Cozy mysteries set in small towns')
        ->assertScript('window.__asked[0]', "Delete this chat? This can't be undone.");

    $page->script('window.__answer = true');
    $page->click($trigger)->wait(0.4)->click('ui-menu button:has-text("Delete"):visible')->wait(1.2)
        ->assertDontSee('Cozy mysteries set in small towns');
    expect(AssistantConversation::where('title', 'Cozy mysteries set in small towns')->exists())->toBeFalse();

    $page->click('aside li:has(a:has-text("Space opera")) button[aria-label^="Options for"]')->wait(0.4)
        ->click('ui-menu button:has-text("Archive"):visible')->wait(1.2)
        ->assertDontSee('Space opera with found family')
        ->click('aside button:has-text("Archived")')->wait(1)
        ->assertSee('Space opera with found family')->assertSee('Shelved idea')
        ->click('aside li:has-text("Shelved idea") button[aria-label^="Options for"]')->wait(0.4)
        ->click('ui-menu button:has-text("Restore"):visible')->wait(1.2)
        ->assertDontSee('Shelved idea');

    expect(AssistantConversation::where('title', 'Shelved idea')->sole()->archived_at)->toBeNull();
});

test('on a phone History opens a drawer that Escape closes and focus returns to the button', function () {
    $page = visit(route('assistant.show', $this->open->id), $this->phone)->wait(1.5);

    $page->assertScript('getComputedStyle(document.querySelector("aside")).display', 'none')
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertScript('document.querySelector("[data-test=history-open]").getBoundingClientRect().height >= 44', true)
        ->assertNoAccessibilityIssues()
        ->click('[data-test=history-open]')->wait(0.8)
        ->assertScript('document.querySelector("dialog[open]") !== null', true)
        ->assertSee('Chat history')
        ->assertSee('Cozy mysteries set in small towns')
        ->assertScript('document.querySelector("dialog[open] a[aria-current=page]").textContent.trim()', 'Dark fantasy with political intrigue')
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'assistant-history-375-drawer')
        ->keys(':root', 'Escape')->wait(0.8)
        ->assertScript('document.querySelector("dialog[open]") === null', true)
        ->assertScript('document.activeElement.getAttribute("data-test")', 'history-open');

    $page->click('[data-test=history-open]')->wait(0.8)->click('dialog[open] a:has-text("A short classic")')->wait(1.5)
        ->assertScript('document.querySelector("dialog[open]") === null', true);
});
