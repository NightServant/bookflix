<?php

use App\Enums\ReadingStatus;
use App\Models\Genre;
use App\Models\MediaType;
use App\Models\User;
use Database\Seeders\MediaTypeSeeder;

/*
 * Onboarding in a real browser: the three steps with the shared checks (audit, no script errors) on a desktop and a phone, empty and with
 * picks, the stepper, the pickers by mouse and by keyboard, and finishing and skipping.
 */

beforeEach(function () {
    (new MediaTypeSeeder)->run();

    $fantasy = Genre::factory()->create(['name' => 'Fantasy', 'slug' => 'fantasy']);
    Genre::factory()->childOf($fantasy, 'Portal fantasy')->create(['name' => 'Isekai', 'slug' => 'isekai']);
    Genre::factory()->childOf($fantasy, 'Portal fantasy')->create(['name' => 'Portal Quest', 'slug' => 'portal-quest']);
    Genre::factory()->create(['name' => 'Romance', 'slug' => 'romance']);

    $this->stories = popularStories(12, ['media_type_id' => MediaType::where('slug', 'manga')->value('id')]);

    $this->user = User::factory()->notOnboarded()->create();
    $this->actingAs($this->user);

    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900]];
    $this->phone = ['viewport' => ['width' => 390, 'height' => 844]];

    $this->path = route('onboarding', absolute: false);
    $this->checked = fn (string $value) => "document.querySelector('input[value=\"{$value}\"]').checked";
    $this->chip = fn (string $value) => "label:has(input[value=\"{$value}\"])";
    $this->noSideScroll = 'document.documentElement.scrollWidth <= window.innerWidth';
    $this->heading = 'document.activeElement.id';
});

test('every step passes the shared checks on desktop and on a phone, empty and with picks, and never scrolls sideways', function (string $size) {
    $page = visit($this->path, $this->{$size})->wait(1);

    $page->assertSee('Make Bookflix yours')->assertSee('What do you like to read?')
        ->assertScript($this->noSideScroll, true)->assertNoJavaScriptErrors()->assertNoAccessibilityIssues()
        ->click(($this->chip)('manga'))->click(($this->chip)('novel'))->wait(0.5) // a ticked chip fades to white; axe reads the colour mid-fade
        ->assertNoAccessibilityIssues()
        ->click('[data-test=next]')->wait(1)
        ->assertSee('Which genres draw you in?')
        ->assertScript($this->noSideScroll, true)->assertNoJavaScriptErrors()->assertNoAccessibilityIssues()
        ->click(($this->chip)('fantasy'))
        ->click('button[aria-controls=genre-fantasy]')->wait(0.4)
        ->click(($this->chip)('isekai'))->wait(0.5)
        ->assertScript('document.querySelector("#genre-fantasy").offsetParent !== null', true)
        ->assertNoAccessibilityIssues()
        ->click('[data-test=next]')->wait(1.5)
        ->assertSee('Which of these have you read?')
        ->assertScript('document.querySelectorAll("[data-test=picks] input[type=checkbox]").length', 12)
        ->assertScript($this->noSideScroll, true)->assertNoJavaScriptErrors()->assertNoAccessibilityIssues()
        ->click('[data-test=finish]')->wait(1)
        ->assertSee('Pick at least 3 stories to finish, or skip for now.')
        ->assertNoAccessibilityIssues()
        ->click('[data-test=picks] li:nth-child(1) label')->click('[data-test=picks] li:nth-child(2) label')->click('[data-test=picks] li:nth-child(3) label')->wait(0.5)
        ->assertScript('document.querySelector("[data-test=picked]").textContent.trim()', '3 of 3 needed')
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
})->with(['desktop', 'phone']);

test('a reader finishes with the mouse and lands on home with their taste and three completed stories saved', function () {
    $page = visit($this->path, $this->desktop)->wait(1);

    $page->click(($this->chip)('manga'))->click('[data-test=next]')->wait(1)
        ->click(($this->chip)('fantasy'))->click('[data-test=next]')->wait(1.5)
        ->click('[data-test=picks] li:nth-child(1) label')->click('[data-test=picks] li:nth-child(4) label')->click('[data-test=picks] li:nth-child(5) label')
        ->click('[data-test=finish]')->wait(2)
        ->assertPathIs('/home')
        ->assertNoJavaScriptErrors();

    expect($this->user->preferences()->count())->toBe(2)
        ->and($this->user->library()->where('status', ReadingStatus::Completed)->count())->toBe(3)
        ->and($this->user->fresh()->profile->onboarded_at)->not->toBeNull();
});

test('the stepper names the steps, marks the current one, and goes back to an earlier step with the picks kept', function () {
    $page = visit($this->path, $this->desktop)->wait(1);

    $page->assertScript('Array.from(document.querySelectorAll("nav[aria-label=\"Onboarding steps\"] li")).map((li) => li.textContent.replace(/\s+/g, " ").trim()).join("|")', 'Step 1 of 3: Types 01 Types|Step 2 of 3: Genres 02 Genres|Step 3 of 3: Stories 03 Stories')
        ->assertScript('document.querySelectorAll("nav[aria-label=\"Onboarding steps\"] button").length', 0)
        ->click(($this->chip)('manga'))->click('[data-test=next]')->wait(1)
        ->assertScript('document.querySelector("li[aria-current=step]").textContent.includes("Genres")', true)
        ->assertScript('document.querySelectorAll("nav[aria-label=\"Onboarding steps\"] button").length', 1)
        ->click('nav[aria-label="Onboarding steps"] button')->wait(1)
        ->assertSee('What do you like to read?')
        ->assertScript(($this->checked)('manga'), true)
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
});

test('the keyboard alone picks, moves on and lands on the new step heading, with a visible ring on the chip', function () {
    $page = visit($this->path, $this->desktop)->wait(1);

    $page->script('document.querySelector("input[value=manga]").focus()');
    $page->assertScript('document.activeElement.value', 'manga')
        ->keys(':focus', 'Space')
        ->assertScript(($this->checked)('manga'), true)
        ->assertScript('getComputedStyle(document.activeElement.nextElementSibling).boxShadow !== "none"', true)
        ->keys(':focus', 'Tab')->keys(':focus', 'Space')
        ->assertScript(($this->checked)('manhua'), true)
        ->keys(':focus', 'Space')
        ->assertScript(($this->checked)('manhua'), false);

    $page->script('document.querySelector("[data-test=next]").focus()');
    $page->keys(':focus', 'Enter')->wait(1)
        ->assertSee('Which genres draw you in?')
        ->assertScript($this->heading, 'step-heading');

    $page->keys(':focus', 'Tab')
        ->assertScript('document.activeElement.type', 'checkbox')
        ->keys(':focus', 'Space')
        ->assertScript(($this->checked)('fantasy'), true)
        ->keys(':focus', 'Tab')
        ->assertScript('document.activeElement.getAttribute("aria-expanded")', 'false')
        ->keys(':focus', 'Enter')->wait(0.4)
        ->assertScript('document.activeElement.getAttribute("aria-expanded")', 'true')
        ->assertScript('document.querySelector("#genre-fantasy").offsetParent !== null', true)
        ->keys(':focus', 'Enter')->wait(0.4)
        ->assertScript('document.querySelector("#genre-fantasy").offsetParent', null)
        ->assertNoJavaScriptErrors();
});

test('skipping leaves the page for home and saves nothing', function () {
    $page = visit($this->path, $this->desktop)->wait(1);

    $page->click(($this->chip)('manga'))->click('[data-test=skip-onboarding]')->wait(2)
        ->assertPathIs('/home')
        ->assertNoJavaScriptErrors();

    expect($this->user->preferences()->count())->toBe(0)
        ->and($this->user->library()->count())->toBe(0)
        ->and($this->user->fresh()->profile->onboarded_at)->not->toBeNull();
});

test('chips, covers, steps and buttons are all at least 44px tall', function (string $size) {
    $page = visit($this->path, $this->{$size})->wait(1);
    $tall = fn (string $selector) => "Array.from(document.querySelectorAll('{$selector}')).every((el) => el.getBoundingClientRect().height >= 44)";

    $page->assertScript($tall('label:has(input[type=checkbox]) > span'), true)
        ->assertScript($tall('[data-test=next], [data-test=skip-onboarding]'), true)
        ->assertScript($tall('nav[aria-label="Onboarding steps"] li > div'), true)
        ->click('[data-test=next]')->wait(1)
        ->assertScript($tall('nav[aria-label="Onboarding steps"] li > button'), true)
        ->assertScript($tall('[data-test=genres] li > div > label, [data-test=genres] button[aria-controls]'), true)
        ->click('[data-test=next]')->wait(1.5)
        ->assertScript($tall('[data-test=picks] label'), true)
        ->assertScript($tall('[data-test=back], [data-test=finish]'), true);
})->with(['desktop', 'phone']);

test('with reduced motion nothing in the flow keeps animating and the steps change at once', function () {
    $page = visit($this->path, $this->desktop + ['reducedMotion' => 'reduce'])->wait(1);

    $page->assertScript('matchMedia("(prefers-reduced-motion: reduce)").matches', true)
        ->assertScript('getComputedStyle(document.querySelector("nav[aria-label=\"Onboarding steps\"] li > div")).transitionDuration', '0s')
        ->assertScript('getComputedStyle(document.querySelector("label:has(input[value=manga]) > span")).transitionDuration', '0s')
        ->click(($this->chip)('manga'))->click('[data-test=next]')->wait(0.8)
        ->assertScript('document.getAnimations().filter((animation) => animation.playState === "running").length', 0)
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
});
