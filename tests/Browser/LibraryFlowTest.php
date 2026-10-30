<?php

use App\Enums\ReadingStatus;
use App\Models\LibraryEntry;
use App\Models\MediaType;
use App\Models\Rating;
use App\Models\ReadingProgress;
use App\Models\User;
use Database\Seeders\MediaTypeSeeder;

/*
 * Phase 5's exit criterion: a new reader onboards, adds a story to their library, logs progress and rates it, in a real browser, with the
 * accessibility audit on every screen of the way.
 */

test('a new reader onboards, adds a story to the library, logs progress and rates it', function (array $viewport) {
    (new MediaTypeSeeder)->run();
    $stories = popularStories(8, ['media_type_id' => MediaType::where('slug', 'manga')->value('id')]);
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    // Home sends a reader who has not onboarded to onboarding.
    $page = visit(route('home', absolute: false), $viewport)->wait(1.5);
    $page->assertPathIs('/onboarding')->assertSee('What do you like to read?')
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues()
        ->click('label:has(input[value=manga])')->click('[data-test=next]')->wait(1)
        ->assertNoAccessibilityIssues()
        ->click('[data-test=next]')->wait(1.5)
        ->click('[data-test=picks] li:nth-child(1) label')->click('[data-test=picks] li:nth-child(2) label')->click('[data-test=picks] li:nth-child(3) label')
        ->assertNoAccessibilityIssues()
        ->click('[data-test=finish]')->wait(2)
        ->assertPathIs('/home')->assertNoJavaScriptErrors();

    expect($user->library()->where('status', ReadingStatus::Completed)->count())->toBe(3);

    // A story that was not on the first three: add it to the library from its page.
    $story = $stories[5];
    $page->navigate(route('stories.show', $story->slug, false))->wait(1)
        ->assertSee($story->title)
        ->click('main button:has-text("Add to Library")')->wait(1)
        ->assertSee('was added to your library')
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();

    expect(LibraryEntry::where('user_id', $user->id)->where('media_id', $story->id)->sole()->status)->toBe(ReadingStatus::WantToRead);

    // Log how far they have read.
    $page->click('[data-test=log-progress]')->wait(0.8)
        ->assertSee('Log your progress')
        ->assertNoAccessibilityIssues()
        ->type('dialog[open] input[name=percent]', '40')
        ->click('dialog[open] button[type=submit]')->wait(1.5)
        ->assertSee('Progress saved')
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();

    expect((float) ReadingProgress::where('user_id', $user->id)->where('media_id', $story->id)->sole()->percent)->toBe(40.0);

    // Rate it.
    $page->click('[data-test=rating] [aria-label="8 out of 10"]')->wait(1)
        ->assertScript('Array.from(document.querySelectorAll("[data-test=rating] [role=radio]")).filter((r) => r.getAttribute("aria-checked") === "true").map((r) => r.dataset.value).join("|")', '8')
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();

    expect(Rating::where('user_id', $user->id)->where('media_id', $story->id)->sole()->score)->toBe(8)
        ->and(LibraryEntry::where('user_id', $user->id)->where('media_id', $story->id)->sole()->status)->toBe(ReadingStatus::Reading);
})->with(['desktop' => fn () => ['viewport' => ['width' => 1440, 'height' => 900]], 'phone' => fn () => ['viewport' => ['width' => 390, 'height' => 844]]]);
