<?php

use Illuminate\Support\Facades\Http;

/*
 * The "Why this?" popover of a recommended tile on Home, in a real browser (the markup and the server side are tests/Feature/Ai/AiExplanationTest):
 * opened by keyboard, the AI sentence and its pill, Escape and an outside click closing it, focus going back, and the panel staying on a phone screen.
 * The model is faked, so nothing leaves the machine.
 */

beforeEach(function () {
    config()->set('bookflix.ai', array_merge(config('bookflix.ai'), [
        'enabled' => true, 'base_url' => 'https://llm.test/v1', 'api_key' => 'sk-test', 'models' => ['fast' => 'tiny', 'smart' => 'big'],
    ]));
    Http::fake(['*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => json_encode(['sentence' => 'Both are slow-burn mysteries, so this one suits a fan of your last read.'])], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20]])]);

    [$user, $catalog] = richReader();
    $user->profile->update(['ai_explanations_enabled' => true]);
    $catalog->each(fn ($story) => $story->update(['cover_url' => '/favicon.svg']));
    $this->actingAs($user->refresh());
    // Home is rendered once before the browser asks for it, so its views compile outside the browser's timeouts.
    $this->get(route('home'))->assertOk();

    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900], 'reducedMotion' => 'reduce'];
    $this->phone = ['viewport' => ['width' => 375, 'height' => 812], 'reducedMotion' => 'reduce'];
    $this->button = 'section[aria-labelledby=shelf-recommended] ul > li:nth-child(1) button[aria-controls^=why-this-]';
    $this->panelVisible = 'getComputedStyle(document.getElementById(document.querySelector("'.$this->button.'").getAttribute("aria-controls"))).display !== "none"';
    $this->expanded = 'document.querySelector("'.$this->button.'").getAttribute("aria-expanded")';
    $this->settle = function ($page) {
        foreach ([0, 500, 1000, 1800] as $offset) {
            $page->script("window.scrollTo(0, {$offset})");
            $page->wait(0.25);
        }

        $page->script('document.querySelector("section[aria-labelledby=shelf-recommended]").scrollIntoView({ block: "center" })');
        $page->wait(0.4);

        return $page;
    };
});

test('Why this? opens by keyboard with the AI sentence and its pill, and Escape closes it and gives focus back', function () {
    $page = ($this->settle)(visit(route('home'), $this->desktop)->wait(1));

    $page->assertScript($this->expanded, 'false')
        ->assertScript($this->panelVisible, false)
        ->script('document.querySelector("'.$this->button.'").focus()');

    $page->keys(':focus', 'Enter')->wait(1.2)
        ->assertScript($this->expanded, 'true')
        ->assertScript($this->panelVisible, true)
        ->assertScript('document.activeElement === document.getElementById(document.querySelector("'.$this->button.'").getAttribute("aria-controls"))', true)
        ->assertScript('document.getElementById(document.querySelector("'.$this->button.'").getAttribute("aria-controls")).textContent.includes("slow-burn mysteries")', true)
        ->assertScript('document.getElementById(document.querySelector("'.$this->button.'").getAttribute("aria-controls")).textContent.includes("AI-generated")', true)
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()
        ->keys(':focus', 'Escape')->wait(0.4)
        ->assertScript($this->expanded, 'false')
        ->assertScript($this->panelVisible, false)
        ->assertScript('document.activeElement === document.querySelector("'.$this->button.'")', true);

    // Opening it again does not ask the model a second time.
    $page->keys(':focus', 'Space')->wait(0.4)->assertScript($this->panelVisible, true);
    Http::assertSentCount(1);
});

test('a click outside closes the popover', function () {
    $page = ($this->settle)(visit(route('home'), $this->desktop)->wait(1));

    $page->click($this->button)->wait(1.2)
        ->assertScript($this->panelVisible, true)
        ->click('section[aria-labelledby=shelf-recommended] h2')->wait(0.4)
        ->assertScript($this->expanded, 'false')
        ->assertScript($this->panelVisible, false);
});

test('on a phone the panel stays inside the screen', function () {
    $page = ($this->settle)(visit(route('home'), $this->phone)->wait(1));
    $inside = '(() => { const r = document.getElementById(document.querySelector("'.$this->button.'").getAttribute("aria-controls")).getBoundingClientRect(); return r.left >= 0 && r.right <= window.innerWidth && r.top >= 0 && r.bottom <= window.innerHeight; })()';

    $page->click($this->button)->wait(1.2)
        ->assertScript($this->panelVisible, true)
        ->assertScript($inside, true)
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues();

    // The last tile of the shelf is the far-right case: scroll the shelf to its end and open that one.
    $page->keys(':root', 'Escape')->wait(0.3);
    $page->script('(() => { const list = document.querySelector("section[aria-labelledby=shelf-recommended] ul"); const scroller = [list, list.parentElement, list.parentElement.parentElement].find((el) => el.scrollWidth > el.clientWidth); scroller.scrollLeft = scroller.scrollWidth; })()');
    $page->wait(0.5);
    $page->script('(() => { const buttons = [...document.querySelectorAll("section[aria-labelledby=shelf-recommended] button[aria-controls^=why-this-]")].filter((b) => b.getBoundingClientRect().right <= window.innerWidth && b.getBoundingClientRect().left >= 0); buttons.at(-1).click(); })()');
    $page->wait(1.2)
        ->assertScript('(() => { const open = document.querySelector("[id^=why-this-]:not([style*=\"display: none\"])"); const r = open.getBoundingClientRect(); return r.left >= 0 && r.right <= window.innerWidth; })()', true);
});
