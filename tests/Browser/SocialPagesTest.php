<?php

use App\Enums\ReadingStatus;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\ExternalReview;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;

/*
 * Phase 10's pages in a real browser: your profile, a story's Reviews tab with the report dialog, Admin, Reports and the Staff picks editor.
 * Each passes the accessibility audit on a desktop and on a phone, with nothing spilling sideways.
 */

beforeEach(function () {
    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900]];
    $this->phone = ['viewport' => ['width' => 375, 'height' => 812]];
    $this->noSpill = 'document.documentElement.scrollWidth <= window.innerWidth';

    // No cover: the story page's 3D book fetches it through the cover proxy, which serves only allowed hosts.
    $this->story = Media::factory()->novel()->create(['title' => 'The Dispossessed', 'cover_url' => null]);
    $this->reader = User::factory()->create(['name' => 'Yoko Reyes']);
    $this->reader->profile->update(['headline' => 'Slow science fiction', 'bio' => 'I read slowly.']);
});

test('your profile passes the audit on a desktop and a phone, with nothing spilling sideways', function () {
    LibraryEntry::factory()->status(ReadingStatus::Reading)->create(['user_id' => $this->reader->id, 'media_id' => $this->story->id]);
    LibraryEntry::factory()->status(ReadingStatus::Completed)->count(9)->create(['user_id' => $this->reader->id]);
    Review::factory()->spoiler()->create(['user_id' => $this->reader->id, 'media_id' => $this->story->id]);
    $this->actingAs($this->reader);

    foreach ([$this->desktop, $this->phone] as $viewport) {
        $page = visit(route('profile.show'), $viewport)->wait(1);
        scrolledThrough($page);

        $page->assertSee('Only you can see this page')->assertScript($this->noSpill, true)->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
    }
});

test('the Reviews tab loads when opened, takes a review and reports one from a dialog that holds the focus', function () {
    $other = User::factory()->create(['name' => 'Dana Whitlock']);
    $other->profile->update(['headline' => 'Manga editor by day']);
    Review::factory()->create(['user_id' => $other->id, 'media_id' => $this->story->id, 'body' => 'Quiet and patient.']);
    ExternalReview::factory()->create(['media_id' => $this->story->id, 'source' => 'hardcover', 'excerpt' => 'An anarchist moon.']);
    $this->actingAs($this->reader);

    $page = visit(route('stories.show', ['slug' => $this->story->slug, 'tab' => 'reviews']), $this->desktop)->wait(1);
    scrolledThrough($page);

    $page->assertSee('Quiet and patient.')->assertSee('Manga editor by day')->assertSee('An anarchist moon.')
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues()
        ->type('#review-body', 'Patient and *strange*.')
        ->click('Post review')->wait(1)
        ->assertSee('Review posted.')
        ->click('[aria-label="Review options for Dana Whitlock"]')->wait(0.5)
        ->click('Report review')->wait(1)
        ->assertSee('Report this review')
        ->assertScript('document.activeElement.closest("dialog[open]") !== null', true)
        ->assertNoAccessibilityIssues()
        ->keys('dialog[open]', 'Escape')->wait(0.5)
        ->assertScript('document.activeElement.getAttribute("aria-label")', 'Review options for Dana Whitlock');

    expect(Review::query()->where('user_id', $this->reader->id)->value('body'))->toBe('Patient and *strange*.');

    $phone = visit(route('stories.show', ['slug' => $this->story->slug, 'tab' => 'reviews']), $this->phone)->wait(1);
    scrolledThrough($phone);
    $phone->assertSee('Quiet and patient.')->assertScript($this->noSpill, true)->assertNoAccessibilityIssues();
});

test('admin reports and the staff picks editor pass the audit, and an admin hides a review and makes a pick', function () {
    $admin = User::factory()->admin()->create();
    $review = Review::factory()->create(['media_id' => $this->story->id, 'body' => 'Rude words here']);
    Report::factory()->of($review)->create();
    $pick = Collection::factory()->editorial()->create(['user_id' => $admin->id, 'name' => 'Quiet Sundays']);
    CollectionItem::factory()->for($pick)->for($this->story, 'media')->create();
    $this->actingAs($admin);

    foreach ([$this->desktop, $this->phone] as $viewport) {
        visit(route('admin.moderation'), $viewport)->wait(1)
            ->assertSee('Rude words here')->assertScript($this->noSpill, true)->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();

        visit(route('admin.staff-picks'), $viewport)->wait(1)
            ->assertSee('Quiet Sundays')->assertScript($this->noSpill, true)->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
    }

    visit(route('admin.moderation'), $this->desktop)->wait(1)->click('Hide')->wait(1)->assertSee('Nothing to look at');
    expect($review->fresh()->status->value)->toBe('hidden');

    visit(route('admin.staff-picks'), $this->desktop)->wait(1)
        ->click('New staff pick')->wait(1)
        ->assertNoAccessibilityIssues()
        ->type('[wire\\:model="name"]', 'Rainy Days')
        ->type('[placeholder="Add a story by title or author"]', 'disposs')->wait(1.5)
        ->click('The Dispossessed')->wait(1)
        ->click('Save')->wait(1)
        ->assertSee('Rainy Days is saved.');

    expect(Collection::query()->where('name', 'Rainy Days')->sole()->items()->pluck('media_id')->all())->toBe([$this->story->id]);
});

test('a staff pick page offers a copy and a link, and the copy lands in your collections', function () {
    $pick = Collection::factory()->editorial()->create(['name' => 'Quiet Sundays', 'slug' => 'quiet-sundays']);
    CollectionItem::factory()->for($pick)->for($this->story, 'media')->create();
    $this->actingAs($this->reader);

    foreach ([$this->desktop, $this->phone] as $viewport) {
        visit(route('collections.show', ['slug' => 'quiet-sundays', 'editors' => 1]), $viewport)->wait(1)
            ->assertSee('Picked by the Bookflix editors')->assertScript($this->noSpill, true)->assertNoJavaScriptErrors()->assertNoAccessibilityIssues();
    }

    visit(route('collections.show', ['slug' => 'quiet-sundays', 'editors' => 1]), $this->desktop)->wait(1)
        ->click('Save a copy')->wait(1)
        ->assertSee('Saved to your collections as a private copy.')
        ->assertNoJavaScriptErrors();

    expect($this->reader->collections()->where('name', 'Quiet Sundays')->exists())->toBeTrue();
});
