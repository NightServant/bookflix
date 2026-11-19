<?php

use App\Enums\AgeRating;
use App\Enums\ReportReason;
use App\Enums\ReviewState;
use App\Jobs\RefreshOutsideReviews;
use App\Models\ExternalRating;
use App\Models\ExternalReview;
use App\Models\ExternalSource;
use App\Models\Media;
use App\Models\Rating;
use App\Models\Report;
use App\Models\Review;
use App\Models\ReviewSummary;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->media = Media::factory()->create(['title' => 'The Dispossessed']);
    $this->reader = User::factory()->create();
});

function reviewer(string $name, ?string $headline = null): User
{
    $user = User::factory()->create(['name' => $name]);
    $user->profile->update(['headline' => $headline]);

    return $user;
}

function storyReviews(Media $media)
{
    return Livewire::test('story-reviews', ['mediaId' => $media->id]);
}

test('the story page has a Reviews tab that the address can open', function () {
    $this->actingAs($this->reader);

    $html = $this->get(route('stories.show', $this->media->slug))->assertOk()->getContent();
    expect(htmlQuery($html, '//button[@role="tab"][normalize-space()="Reviews"]')->length)->toBe(1);

    $html = $this->get(route('stories.show', ['slug' => $this->media->slug, 'tab' => 'reviews']))->assertOk()->getContent();
    expect(htmlQuery($html, '//button[@role="tab"][@aria-selected="true"][normalize-space()="Reviews"]')->length)->toBe(1);
});

test('readers reviews show the name and headline, newest first, with no link to a profile', function () {
    Review::factory()->create(['media_id' => $this->media->id, 'user_id' => reviewer('Dana Whitlock', 'Slow science fiction')->id, 'body' => 'Older **take**', 'created_at' => now()->subDays(3)]);
    Review::factory()->create(['media_id' => $this->media->id, 'user_id' => reviewer('Kei Aoyama')->id, 'body' => 'Newer take', 'created_at' => now()->subDay()]);
    Review::factory()->hidden()->create(['media_id' => $this->media->id, 'body' => 'Hidden by a moderator']);
    $this->actingAs($this->reader);

    $html = storyReviews($this->media)->assertSeeHtmlInOrder(['Kei Aoyama', 'Newer take', 'Dana Whitlock', 'Slow science fiction'])
        ->assertSee('Older <strong>take</strong>', false)
        ->assertDontSee('Hidden by a moderator')
        ->assertSee('2 reviews')
        ->html();

    expect(htmlQuery($html, '//*[@data-section="reader-reviews"]//a[contains(@href,"/profile")]')->length)->toBe(0);
});

test('a spoiler review stays closed until the reader opens it', function () {
    Review::factory()->spoiler()->create(['media_id' => $this->media->id, 'body' => 'The butler did it']);
    $this->actingAs($this->reader);

    $html = storyReviews($this->media)->assertSee('This review has spoilers.')->html();

    expect(htmlQuery($html, '//details[not(@open)]/summary[contains(normalize-space(),"This review has spoilers. Show review")]')->length)->toBe(1)
        ->and(htmlQuery($html, '//details/div[contains(normalize-space(),"The butler did it")]')->length)->toBe(1);
});

test('a reader posts, edits and deletes their own review', function () {
    $this->actingAs($this->reader);

    $component = storyReviews($this->media)
        ->assertSee('Your review')
        ->set('body', 'Patient and *strange*.')
        ->set('hasSpoilers', false)
        ->call('save')
        ->assertHasNoErrors();

    $review = Review::query()->where('user_id', $this->reader->id)->sole();
    expect($review->body)->toBe('Patient and *strange*.');

    $component->assertSee('Patient and <em>strange</em>.', false)
        ->call('edit')
        ->assertSet('body', 'Patient and *strange*.')
        ->set('body', 'Changed my mind.')
        ->set('hasSpoilers', true)
        ->call('save');

    expect($review->refresh()->body)->toBe('Changed my mind.')->and($review->has_spoilers)->toBeTrue();

    $component->call('delete');
    expect(Review::query()->where('user_id', $this->reader->id)->exists())->toBeFalse();
});

test('an empty or too long review is refused with a message', function () {
    $this->actingAs($this->reader);

    storyReviews($this->media)->set('body', '   ')->call('save')->assertHasErrors('body');
    storyReviews($this->media)->set('body', str_repeat('a', 5001))->call('save')->assertHasErrors('body');

    expect(Review::query()->count())->toBe(0);
});

test('your own hidden review shows the moderators notice to you', function () {
    Review::factory()->hidden()->create(['media_id' => $this->media->id, 'user_id' => $this->reader->id, 'body' => 'My hidden words']);
    $this->actingAs($this->reader);

    storyReviews($this->media)->assertSee('My hidden words')->assertSee('A moderator hid this review. Only you can see it.');
});

test('outside reviews show three excerpts with links out that open a new tab, and more on request', function () {
    ExternalSource::query()->updateOrCreate(['key' => 'hardcover'], ExternalSource::factory()->make(['key' => 'hardcover', 'name' => 'Hardcover'])->getAttributes());

    foreach (range(1, 5) as $i) {
        ExternalReview::factory()->create(['media_id' => $this->media->id, 'source' => 'hardcover', 'author_name' => "reader{$i}", 'rating' => 80, 'excerpt' => "Excerpt {$i}", 'published_at' => now()->subDays($i)]);
    }
    ExternalReview::factory()->create(['media_id' => $this->media->id, 'source' => 'hardcover', 'excerpt' => 'Hidden excerpt', 'hidden_at' => now()]);
    $this->actingAs($this->reader);

    $component = storyReviews($this->media)
        ->assertSee('Around the web')
        ->assertSee('Excerpt 1')->assertSee('Excerpt 3')->assertDontSee('Excerpt 4')->assertDontSee('Hidden excerpt')
        ->assertSee('4 out of 5')
        ->assertSee('Show 2 more');

    $links = htmlQuery($component->html(), '//*[@data-section="outside-reviews"]//a[@target="_blank"]');
    expect($links->length)->toBe(3)
        ->and($links->item(0)->getAttribute('rel'))->toBe('noopener noreferrer nofollow ugc')
        ->and($links->item(0)->textContent)->toContain('Read the full review on Hardcover')->toContain('opens in a new tab');

    $component->call('showMoreOutside')->assertSee('Excerpt 5')->assertDontSee('Show 2 more');
});

test('with no outside reviews the section is left out', function () {
    $this->actingAs($this->reader);

    storyReviews($this->media)->assertDontSee('Around the web');
});

test('ratings show Bookflix readers out of ten and each site in its own scale', function () {
    ExternalSource::query()->updateOrCreate(['key' => 'anilist'], ExternalSource::factory()->make(['key' => 'anilist', 'name' => 'AniList'])->getAttributes());
    Rating::factory()->create(['media_id' => $this->media->id, 'score' => 8]);
    Rating::factory()->create(['media_id' => $this->media->id, 'score' => 9]);
    ExternalRating::factory()->create(['media_id' => $this->media->id, 'source' => 'anilist', 'score' => 72, 'votes' => 2301]);
    ExternalRating::factory()->create(['media_id' => $this->media->id, 'source' => 'hardcover', 'score' => 82, 'votes' => 12]);
    $this->actingAs($this->reader);

    storyReviews($this->media)
        ->assertSee('8.5 / 10')->assertSee('2 ratings')
        ->assertSee('72 / 100')->assertSee('2,301 ratings')
        ->assertSee('4.1 / 5')
        ->assertSee('Each site’s own scale. Updated monthly.');
});

test('what readers say shows the summary once there are five reviews', function () {
    ReviewSummary::query()->forceCreate(['media_id' => $this->media->id, 'summary' => 'Two worlds and neither wins.', 'review_count' => 6, 'source_hash' => 'x', 'generated_at' => now()]);
    $this->actingAs($this->reader);

    storyReviews($this->media)->assertSee('What readers say')->assertSee('Two worlds and neither wins.')->assertSee('Sentences picked from 6 readers’ reviews.');
});

test('a reader reports a review and an outside review from the dialog', function () {
    $review = Review::factory()->create(['media_id' => $this->media->id, 'user_id' => reviewer('Dana Whitlock')->id]);
    $outside = ExternalReview::factory()->create(['media_id' => $this->media->id]);
    $this->actingAs($this->reader);

    storyReviews($this->media)
        ->call('openReport', 'review', $review->id)
        ->assertSet('reportName', 'Dana Whitlock')
        ->set('reason', ReportReason::Spoiler->value)
        ->set('details', 'Gives the ending away')
        ->call('sendReport')
        ->assertHasNoErrors()
        ->call('openReport', 'external_review', $outside->id)
        ->set('reason', ReportReason::Spam->value)
        ->call('sendReport')
        ->assertHasNoErrors();

    expect(Report::query()->pluck('reportable_type')->sort()->values()->all())->toBe(['external_review', 'review'])
        ->and(Report::query()->where('reportable_type', 'review')->value('details'))->toBe('Gives the ending away');
});

test('a report needs a reason and refuses an unknown target', function () {
    $review = Review::factory()->create(['media_id' => $this->media->id]);
    $this->actingAs($this->reader);

    storyReviews($this->media)->call('openReport', 'review', $review->id)->set('reason', '')->call('sendReport')->assertHasErrors('reason');
    storyReviews($this->media)->call('openReport', 'collection', 1)->set('reason', 'spam')->call('sendReport')->assertHasErrors('report');

    expect(Report::query()->count())->toBe(0);
});

test('opening the tab asks for fresh outside reviews once', function () {
    $this->actingAs($this->reader);

    storyReviews($this->media);
    storyReviews($this->media);

    Queue::assertPushed(RefreshOutsideReviews::class, 1);
});

test('a story behind the mature gate shows no reviews', function () {
    $gated = Media::factory()->create(['age_rating' => AgeRating::Adult]);
    Review::factory()->create(['media_id' => $gated->id, 'body' => 'Gated words']);
    $this->actingAs($this->reader);

    storyReviews($gated)->assertForbidden();
});

test('the tab reads a fixed number of queries however many reviews there are', function () {
    $count = function (int $n): int {
        $media = Media::factory()->create();
        foreach (range(1, $n) as $i) {
            Review::factory()->create(['media_id' => $media->id, 'user_id' => reviewer("R{$i}", "Line {$i}")->id]);
            ExternalReview::factory()->create(['media_id' => $media->id]);
        }
        $this->actingAs(User::factory()->create());
        DB::flushQueryLog();
        DB::enableQueryLog();
        storyReviews($media);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($count(6))->toBe($count(1));
});

test('hidden reviews of others never reach the page, however the list is read', function () {
    Review::factory()->create(['media_id' => $this->media->id, 'status' => ReviewState::Hidden, 'body' => 'Not for you']);
    $this->actingAs($this->reader);

    storyReviews($this->media)->call('showMoreReaders')->assertDontSee('Not for you');
});
