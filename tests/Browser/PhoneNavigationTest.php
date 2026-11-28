<?php

use App\Models\AssistantConversation;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Database\Seeders\MediaTypeSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Support\Facades\Http;

/*
 * Phone navigation: a reader reaches Settings and Sign out from the top bar, the admin sidebar drawer locks the page and is closed after a link
 * is followed, and the assistant's History drawer closes on Escape and gives focus back. Every opener and close control must sit fully inside the
 * window: a fixed bar can push a control off the screen without widening the page (the lesson of the landing menu).
 */

beforeEach(function () {
    $this->ready = 'document.readyState === "complete" && !! window.Livewire';
    $this->menuOpen = 'Array.from(document.querySelectorAll("ui-menu a, ui-menu button")).some((el) => el.offsetParent !== null)';
    $this->drawerOpen = 'document.querySelector("[data-flux-sidebar]").getBoundingClientRect().left >= 0';
    $this->drawerShut = 'document.querySelector("[data-flux-sidebar]").getBoundingClientRect().right <= 0';
    $this->phone = ['viewport' => ['width' => 375, 'height' => 667], 'reducedMotion' => 'reduce'];

    /** A script: is the control with this selector fully inside the window, and not covered at its centre. */
    $this->inside = fn (string $selector): string => '(() => {
        const el = document.querySelector('.json_encode($selector).');
        if (! el) return "missing";
        const box = el.getBoundingClientRect();
        if (box.width < 1 || box.left < 0 || box.right > window.innerWidth) return "outside " + Math.round(box.left) + ".." + Math.round(box.right);
        const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);
        return el.contains(hit) ? "ok" : "covered by " + (hit ? hit.tagName.toLowerCase() + "." + String(hit.className).slice(0, 40) : "nothing");
    })()';
});

test('a reader on a phone reaches Settings and signs out in two taps or fewer', function () {
    $this->actingAs(User::factory()->create());
    $page = visit('/home', $this->phone);
    $page->assertScript(browserUntil($this->ready), true);

    $page->assertScript(($this->inside)('[data-test="sidebar-menu-button"]'), 'ok')
        ->click('[data-test="sidebar-menu-button"]')->assertScript(browserUntil($this->menuOpen), true)
        ->assertScript('Array.from(document.querySelectorAll("ui-menu a, ui-menu button")).filter((el) => el.offsetParent !== null).map((el) => el.textContent.trim()).filter(Boolean).join("|")', 'Settings|Log out')
        ->click('ui-menu a[href$="/settings/profile"]')->assertScript(browserUntil('location.pathname === "/settings/profile"'), true)
        ->assertPathIs('/settings/profile');

    $page->click('[data-test="sidebar-menu-button"]')->assertScript(browserUntil($this->menuOpen), true)
        ->click('[data-test="logout-button"]')->assertScript(browserUntil('location.pathname === "/"'), true)
        ->assertPathIs('/');
});

test('the account control is on screen at 320 and 375', function (int $width) {
    $this->actingAs(User::factory()->create());

    visit('/home', ['viewport' => ['width' => $width, 'height' => 667], 'reducedMotion' => 'reduce'])
        ->assertScript(browserUntil($this->ready), true)
        ->assertScript(($this->inside)('[data-test="sidebar-menu-button"]'), 'ok')
        ->click('[data-test="sidebar-menu-button"]')->assertScript(browserUntil($this->menuOpen), true)
        // Every item of the open menu is a 44px target, and the menu itself is inside the window.
        ->assertScript('Array.from(document.querySelectorAll("ui-menu a, ui-menu button")).filter((el) => el.offsetParent !== null && el.textContent.trim()).every((el) => el.getBoundingClientRect().height >= 44)', true)
        ->assertScript('(() => { const box = document.querySelector("ui-menu").getBoundingClientRect(); return box.left >= 0 && box.right <= window.innerWidth; })()', true);
})->with([320, 375]);

test('the admin drawer locks the page, closes after navigating, and Escape gives focus back', function (int $width) {
    Http::fake(['*/health' => Http::response(['status' => 'ok'])]);
    $this->actingAs(User::factory()->admin()->create());
    User::factory()->count(30)->create();

    $toggle = 'button[aria-label="Open menu"]';
    $closed = 'document.querySelector("[data-flux-sidebar]").getBoundingClientRect().right <= 0';
    $page = visit('/admin/users', ['viewport' => ['width' => $width, 'height' => 667], 'reducedMotion' => 'reduce']);
    $page->assertScript(browserUntil($this->ready), true);

    $page->assertScript(($this->inside)($toggle), 'ok')
        ->assertScript($closed, true)
        ->assertScript('getComputedStyle(document.documentElement).overflow !== "hidden" && getComputedStyle(document.body).overflow !== "hidden"', true)
        ->click($toggle)->assertScript(browserUntil($this->drawerOpen), true)
        ->assertScript('document.querySelector("[data-flux-sidebar]").getBoundingClientRect().left >= 0', true)
        ->assertScript(($this->inside)('[data-flux-sidebar-collapse] button'), 'ok')
        // The page behind it cannot scroll: the root or the body is locked while the drawer is open (and not before or after).
        ->assertScript('getComputedStyle(document.documentElement).overflow === "hidden" || getComputedStyle(document.body).overflow === "hidden"', true)
        ->keys(':root', 'Escape')->assertScript(browserUntil($this->drawerShut), true)
        ->assertScript($closed, true)
        ->assertScript('getComputedStyle(document.documentElement).overflow !== "hidden" && getComputedStyle(document.body).overflow !== "hidden"', true)
        ->assertScript('document.activeElement.getAttribute("aria-label")', 'Open menu');

    $page->click($toggle)->assertScript(browserUntil($this->drawerOpen), true)
        ->click('[data-flux-sidebar] a[href$="/admin/providers"]')->assertScript(browserUntil('location.pathname === "/admin/providers"'), true)
        ->assertPathIs('/admin/providers')
        ->assertScript($closed, true)
        ->assertScript('getComputedStyle(document.documentElement).overflow !== "hidden" && getComputedStyle(document.body).overflow !== "hidden"', true);
})->with([320, 375]);

test('the admin drawer lock is lifted when the window grows, and when a link leaves the admin shell', function () {
    Http::fake(['*/health' => Http::response(['status' => 'ok'])]);
    $this->actingAs(User::factory()->admin()->create());

    $toggle = 'button[aria-label="Open menu"]';
    $unlocked = 'getComputedStyle(document.documentElement).overflow !== "hidden" && document.documentElement.style.overflow === "" && document.documentElement.style.paddingRight === ""';

    // Growing the window while the drawer is open.
    $page = visit('/admin/users', ['viewport' => ['width' => 375, 'height' => 667], 'reducedMotion' => 'reduce']);
    $page->assertScript(browserUntil($this->ready), true);
    $page->click($toggle)->assertScript(browserUntil($this->drawerOpen), true)
        ->assertScript('getComputedStyle(document.documentElement).overflow', 'hidden')
        ->resize(1280, 800)->assertScript(browserUntil('document.documentElement.style.overflow === ""'), true)
        ->assertScript($unlocked, true);

    // Following the Account link from the open drawer to a page that is not an admin page.
    $again = visit('/admin/users', ['viewport' => ['width' => 375, 'height' => 667], 'reducedMotion' => 'reduce']);
    $again->assertScript(browserUntil($this->ready), true);
    $again->click($toggle)->assertScript(browserUntil($this->drawerOpen), true)
        ->click('[data-flux-sidebar] a[href$="/settings/profile"]')->assertScript(browserUntil('location.pathname === "/settings/profile"'), true)
        ->assertPathIs('/settings/profile')
        ->assertScript($unlocked, true);
});

test('the assistant History drawer closes on Escape and returns focus, with its controls on screen', function (int $width) {
    $this->seed([MediaTypeSeeder::class, TaxonomySeeder::class, DeweySeeder::class]);
    config()->set('bookflix.ai', array_merge(config('bookflix.ai'), [
        'enabled' => true, 'base_url' => 'https://llm.test/v1', 'api_key' => 'sk-test',
        'models' => ['fast' => 'tiny-model', 'smart' => 'big-model'], 'log_payloads' => false,
    ]));

    $user = User::factory()->create();
    $this->actingAs($user);
    $chat = AssistantConversation::factory()->for($user)->create(['title' => 'Dark fantasy', 'last_message_at' => now()]);
    $chat->messages()->create(['role' => 'user', 'text' => 'dark fantasy']);

    $page = visit(route('assistant.show', $chat->id, false), ['viewport' => ['width' => $width, 'height' => 667], 'reducedMotion' => 'reduce']);
    $page->assertScript(browserUntil($this->ready), true);

    $page->assertScript(($this->inside)('[data-test=history-open]'), 'ok')
        ->click('[data-test=history-open]')->assertScript(browserUntil('document.querySelector("dialog[open]") !== null'), true)
        ->assertScript('document.querySelector("dialog[open]") !== null', true)
        ->assertScript(($this->inside)('dialog[open] button[aria-label="Close history"]'), 'ok')
        ->keys(':root', 'Escape')->assertScript(browserUntil('document.querySelector("dialog[open]") === null'), true)
        ->assertScript('document.querySelector("dialog[open]") === null', true)
        ->assertScript('document.activeElement.getAttribute("data-test")', 'history-open');
})->with([320, 375]);
