<?php

use App\Enums\InteractionEvent;
use App\Enums\ReadingStatus;
use App\Events\LibraryChanged;
use App\Http\Middleware\EnsureOnboarded;
use App\Jobs\RecordInteraction;
use App\Models\Genre;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\MediaType;
use App\Models\Theme;
use App\Models\User;
use App\Models\UserInteraction;
use App\Models\UserPreference;
use Database\Seeders\MediaTypeSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/**
 * Three media types, a fantasy family with two subgenres, a romance family, and a mature family, so every step has something to offer.
 *
 * @return array{manga: MediaType, novel: MediaType, fantasy: Genre, isekai: Genre, romance: Genre, mature: Genre}
 */
function onboardingTaxonomy(): array
{
    $fantasy = Genre::factory()->create(['name' => 'Fantasy', 'slug' => 'fantasy']);
    $mature = Genre::factory()->mature()->create(['name' => 'Mature Family', 'slug' => 'mature-family']);

    return [
        'manga' => MediaType::firstOrCreate(['slug' => 'manga'], MediaTypeSeeder::TYPES['manga']),
        'novel' => MediaType::firstOrCreate(['slug' => 'novel'], MediaTypeSeeder::TYPES['novel']),
        'fantasy' => $fantasy,
        'isekai' => Genre::factory()->childOf($fantasy, 'Portal fantasy')->create(['name' => 'Isekai', 'slug' => 'isekai']),
        'romance' => Genre::factory()->create(['name' => 'Romance', 'slug' => 'romance']),
        'mature' => $mature,
    ];
}

/**
 * Popular stories with a cover, best first.
 *
 * @return list<Media>
 */
function onboardingStories(int $count = 6, ?string $type = null, ?Genre $genre = null): array
{
    $stories = popularStories($count, $type === null ? [] : ['media_type_id' => MediaType::where('slug', $type)->value('id')], 'https://covers.test/{n}.jpg');

    if ($genre !== null) {
        foreach ($stories as $story) {
            $story->genres()->attach($genre);
        }
    }

    return $stories;
}

function onboardingIds(array $stories): array
{
    return array_map(fn (Media $story): int => $story->id, $stories);
}

// --- who is sent to onboarding -----------------------------------------------------------------------------------------------------------

test('a reader who has not onboarded is sent from home to onboarding', function () {
    $this->actingAs(User::factory()->notOnboarded()->create())->get(route('home'))->assertRedirect(route('onboarding'));
});

test('an onboarded reader reaches home, and the factory makes onboarded readers by default', function () {
    $user = User::factory()->create();

    expect($user->profile->onboarded_at)->not->toBeNull();

    $this->actingAs($user)->get(route('home'))->assertOk();
});

test('a brand new reader, registered the way readers register, is sent to onboarding once verified', function () {
    $this->post(route('register.store'), ['name' => 'New Reader', 'email' => 'new@example.test', 'password' => 'password', 'password_confirmation' => 'password'])->assertSessionHasNoErrors();

    $user = User::where('email', 'new@example.test')->sole();

    // Registration writes no profile: the reader has not onboarded because there is nothing to say they have.
    expect($user->profile()->exists())->toBeFalse();

    $user->markEmailAsVerified();

    $this->actingAs($user->fresh())->get(route('home'))->assertRedirect(route('onboarding'));
});

test('the onboarding page itself never redirects, whoever opens it', function () {
    $this->actingAs(User::factory()->notOnboarded()->create())->get(route('onboarding'))->assertOk();
    $this->actingAs(User::factory()->create())->get(route('onboarding'))->assertOk();
});

test('a reader who has not onboarded still reaches settings, logout, admin, verification and the rest of the app', function () {
    $this->actingAs($user = User::factory()->notOnboarded()->create());

    foreach (['profile.edit', 'privacy.edit', 'preferences.edit', 'discover', 'search', 'library.index', 'collections.index', 'history.index'] as $name) {
        $this->get(route($name))->assertOk();
    }

    $this->post(route('logout'))->assertRedirect('/');
    $this->assertGuest();

    $this->actingAs(User::factory()->admin()->notOnboarded()->create())->get(route('admin.index'))->assertOk();
    $this->actingAs(User::factory()->unverified()->notOnboarded()->create())->get('/email/verify')->assertOk();
});

test('the middleware lets onboarding, settings, logout, admin and verification through even where a route group puts it on them', function (string $method, string $route) {
    $user = User::factory()->notOnboarded()->create();
    $request = Request::create(route($route, absolute: false), $method);
    $request->setRouteResolver(fn () => app('router')->getRoutes()->match($request));
    $request->setUserResolver(fn () => $user);

    $response = (new EnsureOnboarded)->handle($request, fn () => response('through'));

    expect($response->getContent())->toBe('through');
})->with([
    ['GET', 'onboarding'],
    ['GET', 'profile.edit'],
    ['GET', 'privacy.edit'],
    ['GET', 'security.edit'],
    ['GET', 'preferences.edit'],
    ['POST', 'logout'],
    ['GET', 'admin.index'],
    ['GET', 'verification.notice'],
    ['POST', 'verification.send'],
]);

test('the middleware sends a reader who has not onboarded from any other page to onboarding, but only for a page view', function () {
    $user = User::factory()->notOnboarded()->create();
    $request = fn (string $method) => tap(Request::create('/home', $method), function (Request $request) use ($user) {
        $request->setRouteResolver(fn () => app('router')->getRoutes()->match($request));
        $request->setUserResolver(fn () => $user);
    });

    expect((new EnsureOnboarded)->handle($request('GET'), fn () => response('through'))->headers->get('Location'))->toBe(route('onboarding'))
        ->and((new EnsureOnboarded)->handle($request('HEAD'), fn () => response('through'))->headers->get('Location'))->toBe(route('onboarding'));

    // A guest is the auth middleware's business, and so is a form post.
    $guest = Request::create('/home', 'GET');
    $guest->setRouteResolver(fn () => app('router')->getRoutes()->match($guest));

    expect((new EnsureOnboarded)->handle($guest, fn () => response('through'))->getContent())->toBe('through');
});

test('a guest asking for onboarding is sent to sign in, and an unverified reader to verification', function () {
    $this->get(route('onboarding'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->notOnboarded()->create())->get(route('onboarding'))->assertRedirect('/email/verify');
});

// --- the steps ---------------------------------------------------------------------------------------------------------------------------

test('the page guides the reader and offers three steps, starting with the formats', function () {
    onboardingTaxonomy();
    $this->actingAs(User::factory()->notOnboarded()->create());

    $html = Livewire::test('pages::onboarding.index')->assertSet('step', 1)->html();

    expect(trim(htmlQuery($html, '//h1')->item(0)->textContent))->not->toBe('')
        ->and(htmlQuery($html, '(//h1)[1]/following::p[1]')->item(0)->textContent)->not->toBe('')
        ->and(collect(htmlQuery($html, '//nav[@aria-label="Onboarding steps"]//li'))->map(fn ($li) => preg_replace('/\s+/', ' ', trim($li->textContent)))->all())
        ->toBe(['Step 1 of 3: Types 01 Types', 'Step 2 of 3: Genres 02 Genres', 'Step 3 of 3: Stories 03 Stories'])
        ->and(htmlQuery($html, '//li[@aria-current="step"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//input[@type="checkbox"][@value="manga"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//input[@type="checkbox"][@value="novel"]')->length)->toBe(1);
});

test('step two lists the genre families with their subgenres, and the mature family only for a reader who may see it', function () {
    onboardingTaxonomy();

    $this->actingAs(User::factory()->notOnboarded()->create());
    $gated = Livewire::test('pages::onboarding.index')->call('next')->assertSet('step', 2)->html();

    expect(htmlQuery($gated, '//input[@type="checkbox"][@value="fantasy"]')->length)->toBe(1)
        ->and(htmlQuery($gated, '//input[@type="checkbox"][@value="isekai"]')->length)->toBe(1)
        ->and(htmlQuery($gated, '//input[@type="checkbox"][@value="romance"]')->length)->toBe(1)
        ->and(htmlQuery($gated, '//input[@value="mature-family"]')->length)->toBe(0)
        ->and($gated)->not->toContain('Mature Family');

    $this->actingAs(User::factory()->notOnboarded()->showsMature()->create());
    $open = Livewire::test('pages::onboarding.index')->call('next')->html();

    expect(htmlQuery($open, '//input[@type="checkbox"][@value="mature-family"]')->length)->toBe(1);
});

test('the reader moves forward one step at a time and back to any step before the current one', function () {
    onboardingTaxonomy();
    $this->actingAs(User::factory()->notOnboarded()->create());

    Livewire::test('pages::onboarding.index')
        ->call('goTo', 3)->assertSet('step', 1) // no jumping ahead
        ->call('next')->assertSet('step', 2)
        ->call('next')->assertSet('step', 3)
        ->call('next')->assertSet('step', 3) // the last step finishes instead
        ->call('back')->assertSet('step', 2)
        ->call('goTo', 1)->assertSet('step', 1)
        ->call('back')->assertSet('step', 1)
        ->call('goTo', 0)->assertSet('step', 1)
        ->call('goTo', 9)->assertSet('step', 1);
});

test('the current step carries aria-current and the steps before it can be reached from the stepper', function () {
    onboardingTaxonomy();
    $this->actingAs(User::factory()->notOnboarded()->create());

    $html = Livewire::test('pages::onboarding.index')->call('next')->html();

    expect(htmlQuery($html, '//li[@aria-current="step"]')->item(0)->textContent)->toContain('Genres')
        ->and(htmlQuery($html, '//nav[@aria-label="Onboarding steps"]//button[@*[name()="wire:click"]="goTo(1)"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//nav[@aria-label="Onboarding steps"]//button[@*[name()="wire:click"]="goTo(3)"]')->length)->toBe(0);
});

test('step three offers popular stories with covers that match the chosen formats and genres, best first', function () {
    ['fantasy' => $fantasy] = onboardingTaxonomy();
    $matching = onboardingStories(4, 'manga', $fantasy);
    $other = Media::factory()->create(['title' => 'Unrelated Novel', 'cover_url' => 'https://covers.test/x.jpg', 'popularity_score' => 2, 'media_type_id' => MediaType::where('slug', 'novel')->value('id')]);
    Media::factory()->create(['title' => 'No Cover Manga', 'cover_url' => null, 'popularity_score' => 9, 'media_type_id' => MediaType::where('slug', 'manga')->value('id')])->genres()->attach($fantasy);
    $this->actingAs(User::factory()->notOnboarded()->create());

    $page = Livewire::test('pages::onboarding.index')->set('mediaTypes', ['manga'])->set('genres', ['fantasy'])->call('next')->call('next');
    $picks = $page->instance()->picks;

    expect($picks->take(4)->pluck('id')->all())->toBe(onboardingIds($matching))
        ->and($picks->pluck('cover_url')->every(fn (?string $cover) => $cover !== null))->toBeTrue()
        ->and($picks->pluck('title')->all())->not->toContain('No Cover Manga');
    // The story that matches neither filter only comes after the ones that match: it tops the list up, it does not lead it.
    expect($picks->search(fn (Media $story) => $story->id === $other->id))->toBeGreaterThanOrEqual(4);
});

test('with no choices made the picks are the most popular stories overall, up to a page of them', function () {
    onboardingTaxonomy();
    $stories = onboardingStories(30);
    $this->actingAs(User::factory()->notOnboarded()->create());

    $picks = Livewire::test('pages::onboarding.index')->call('next')->call('next')->instance()->picks;

    expect($picks)->toHaveCount(24)->and($picks->pluck('id')->all())->toBe(array_slice(onboardingIds($stories), 0, 24));
});

test('a narrow choice is topped up with popular stories so there is always enough to pick from', function () {
    ['fantasy' => $fantasy] = onboardingTaxonomy();
    onboardingStories(2, 'manga', $fantasy);
    Media::factory()->count(5)->sequence(fn ($sequence) => ['title' => "Filler {$sequence->index}", 'cover_url' => 'https://covers.test/f.jpg', 'popularity_score' => 0.1])->create();
    $this->actingAs(User::factory()->notOnboarded()->create());

    $picks = Livewire::test('pages::onboarding.index')->set('genres', ['fantasy'])->call('next')->call('next')->instance()->picks;

    expect($picks)->toHaveCount(7);
});

test('revision item 2: picks never include a story behind the mature gate until the reader opted in', function () {
    onboardingTaxonomy();
    $hidden = matureStory('Hidden Erotic Tale');
    $hidden->forceFill(['cover_url' => 'https://covers.test/h.jpg', 'popularity_score' => 5])->save();
    onboardingStories(3);

    $this->actingAs(User::factory()->notOnboarded()->create());
    $gated = Livewire::test('pages::onboarding.index')->call('next')->call('next')->instance()->picks;

    $this->actingAs(User::factory()->notOnboarded()->showsMature()->create());
    $open = Livewire::test('pages::onboarding.index')->call('next')->call('next')->instance()->picks;

    expect($gated->pluck('title')->all())->not->toContain('Hidden Erotic Tale')
        ->and($open->pluck('title')->first())->toBe('Hidden Erotic Tale');
});

test('merged stories and stories already in the library are not offered', function () {
    onboardingTaxonomy();
    [$kept, $inLibrary, $merged] = onboardingStories(3);
    $merged->update(['merged_into_id' => $kept->id]);
    $this->actingAs($user = User::factory()->notOnboarded()->create());
    LibraryEntry::factory()->for($user)->for($inLibrary)->create();

    $picks = Livewire::test('pages::onboarding.index')->call('next')->call('next')->instance()->picks;

    expect($picks->pluck('id')->all())->toBe([$kept->id]);
});

test('step three shows the stories as covers the reader ticks, with a running count of the three needed', function () {
    onboardingTaxonomy();
    onboardingStories(5);
    $this->actingAs(User::factory()->notOnboarded()->create());

    $html = Livewire::test('pages::onboarding.index')->call('next')->call('next')->html();

    expect(htmlQuery($html, '//label//input[@type="checkbox"][@*[name()="wire:model"]="stories"]')->length)->toBe(5)
        ->and(htmlQuery($html, '//label//img[@src="https://covers.test/1.jpg"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//a[contains(@href,"/stories/")]')->length)->toBe(0)
        ->and($html)->toContain('data-test="picked"');
});

test('stories picked earlier that are no longer on offer are dropped when step three opens', function () {
    onboardingTaxonomy();
    $stories = onboardingStories(30);
    $this->actingAs(User::factory()->notOnboarded()->create());

    Livewire::test('pages::onboarding.index')
        ->call('next')
        // The reader went back from step three and changed the choices: the 30th story is past the 24 on offer now.
        ->set('stories', [$stories[0]->id, $stories[29]->id])
        ->call('next')
        ->assertSet('stories', [$stories[0]->id]);
});

// --- finishing and skipping --------------------------------------------------------------------------------------------------------------

test('finishing with fewer than three stories is a validation error and onboards nobody', function () {
    onboardingTaxonomy();
    $stories = onboardingStories(5);
    $this->actingAs($user = User::factory()->notOnboarded()->create());

    Livewire::test('pages::onboarding.index')
        ->set('stories', array_slice(onboardingIds($stories), 0, 2))
        ->call('finish')
        ->assertHasErrors(['stories' => 'min'])
        ->assertNoRedirect();

    expect($user->fresh()->profile->onboarded_at)->toBeNull()->and($user->library()->count())->toBe(0)->and($user->preferences()->count())->toBe(0);
});

test('finishing saves the preferences, adds the stories as completed with the onboarding surface, and onboards the reader', function () {
    ['manga' => $manga, 'fantasy' => $fantasy] = onboardingTaxonomy();
    $stories = onboardingStories(3);
    $this->actingAs($user = User::factory()->notOnboarded()->create());

    Livewire::test('pages::onboarding.index')
        ->set('mediaTypes', ['manga'])->set('genres', ['fantasy'])->set('stories', onboardingIds($stories))
        ->call('finish')
        ->assertHasNoErrors()
        ->assertRedirect(route('home'));

    expect($user->preferences()->count())->toBe(2)
        ->and($user->preferences()->where('preferable_type', 'media_type')->sole()->preferable_id)->toBe($manga->id)
        ->and($user->preferences()->where('preferable_type', 'genre')->sole()->preferable_id)->toBe($fantasy->id)
        ->and($user->preferences()->pluck('weight')->unique()->all())->toBe([1])
        ->and($user->library()->count())->toBe(3)
        ->and($user->library()->where('status', ReadingStatus::Completed)->whereNotNull('completed_at')->count())->toBe(3)
        ->and($user->fresh()->profile->onboarded_at)->not->toBeNull();

    $adds = UserInteraction::where('event', InteractionEvent::LibraryAdd)->get();

    // Three adds, each with the surface; picking a story you have read is not a reading_complete event of the recommender (LogProgress's job).
    expect($adds)->toHaveCount(3)->and($adds->every(fn (UserInteraction $row) => $row->context === ['surface' => 'onboarding']))->toBeTrue()
        ->and(interactionEvents())->toBe(['library_add', 'library_add', 'library_add']);

    $this->get(route('home'))->assertOk();
});

test('a finish that fails half way leaves no library entries, no onboarding mark and no interaction queued', function () {
    onboardingTaxonomy();
    $stories = onboardingStories(3);
    $this->actingAs($user = User::factory()->notOnboarded()->create());
    $queue = recordingQueue();

    // The third add fails after the first two were recorded.
    $adds = 0;
    Event::listen(LibraryChanged::class, function () use (&$adds): void {
        ++$adds === 3 && throw new RuntimeException('the third add failed');
    });

    expect(fn () => Livewire::test('pages::onboarding.index')->set('stories', onboardingIds($stories))->call('finish'))->toThrow(RuntimeException::class, 'the third add failed');

    expect($queue->pushed)->toBe([])
        ->and($user->library()->count())->toBe(0)
        ->and($user->fresh()->profile->onboarded_at)->toBeNull();
});

test('a finish that works queues one interaction for each story once it has committed', function () {
    onboardingTaxonomy();
    $stories = onboardingStories(3);
    $this->actingAs(User::factory()->notOnboarded()->create());
    $queue = recordingQueue();

    Livewire::test('pages::onboarding.index')->set('stories', onboardingIds($stories))->call('finish')->assertRedirect(route('home'));

    expect($queue->pushed)->toBe([RecordInteraction::class, RecordInteraction::class, RecordInteraction::class]);
});

test('an opted out reader finishes the same way and leaves no interaction rows', function () {
    onboardingTaxonomy();
    $stories = onboardingStories(3);
    $this->actingAs($user = User::factory()->notOnboarded()->create());
    $user->profile()->updateOrCreate([], ['personalization_enabled' => false]);

    Livewire::test('pages::onboarding.index')->set('genres', ['fantasy'])->set('stories', onboardingIds($stories))->call('finish')->assertRedirect(route('home'));

    expect(UserInteraction::count())->toBe(0)
        ->and($user->library()->count())->toBe(3)
        ->and($user->preferences()->count())->toBe(1)
        ->and($user->fresh()->profile->onboarded_at)->not->toBeNull();
});

test('skipping onboards the reader and writes nothing else', function () {
    onboardingTaxonomy();
    onboardingStories(3);
    $this->actingAs($user = User::factory()->notOnboarded()->create());

    Livewire::test('pages::onboarding.index')
        ->set('mediaTypes', ['manga'])->set('genres', ['fantasy'])
        ->call('skip')
        ->assertRedirect(route('home'));

    expect($user->preferences()->count())->toBe(0)
        ->and($user->library()->count())->toBe(0)
        ->and(UserInteraction::count())->toBe(0)
        ->and($user->fresh()->profile->onboarded_at)->not->toBeNull();

    $this->get(route('home'))->assertOk();
});

test('finishing and skipping change the signed in reader and nobody else', function () {
    onboardingTaxonomy();
    $stories = onboardingStories(3);
    $other = User::factory()->notOnboarded()->create();
    $this->actingAs($user = User::factory()->notOnboarded()->create());

    Livewire::test('pages::onboarding.index')->set('stories', onboardingIds($stories))->call('finish');
    Livewire::test('pages::onboarding.index')->call('skip');

    expect($other->fresh()->profile->onboarded_at)->toBeNull()->and($other->library()->count())->toBe(0);
});

// --- input the page must refuse ----------------------------------------------------------------------------------------------------------

test('unknown formats and genres, and a mature genre for a gated reader, are refused', function (string $property, array $value) {
    onboardingTaxonomy();
    $stories = onboardingStories(3);
    $this->actingAs($user = User::factory()->notOnboarded()->create());

    Livewire::test('pages::onboarding.index')
        ->set($property, $value)->set('stories', onboardingIds($stories))
        ->call('finish')
        ->assertHasErrors()
        ->assertNoRedirect();

    expect($user->fresh()->profile->onboarded_at)->toBeNull()->and($user->library()->count())->toBe(0)->and($user->preferences()->count())->toBe(0);
})->with([
    'a genre that does not exist' => ['genres', ['no-such-genre']],
    'a format that does not exist' => ['mediaTypes', ['no-such-type']],
    'the mature family' => ['genres', ['mature-family']],
    'a genre id instead of a slug' => ['genres', [12345]],
    'a nested value' => ['genres', [['fantasy']]],
]);

test('the first two steps refuse an unknown choice before moving on', function () {
    onboardingTaxonomy();
    $this->actingAs(User::factory()->notOnboarded()->create());

    Livewire::test('pages::onboarding.index')->set('mediaTypes', ['nope'])->call('next')->assertHasErrors('mediaTypes.0')->assertSet('step', 1);
    Livewire::test('pages::onboarding.index')->call('next')->set('genres', ['nope'])->call('next')->assertHasErrors('genres.0')->assertSet('step', 2);
});

test('story ids that are unknown, merged, behind the gate or not ids at all are refused', function (callable $ids) {
    onboardingTaxonomy();
    $good = onboardingStories(3);
    $merged = Media::factory()->create(['merged_into_id' => $good[0]->id]);
    $hidden = matureStory();
    $this->actingAs($user = User::factory()->notOnboarded()->create());

    Livewire::test('pages::onboarding.index')
        ->set('stories', $ids($good, $merged, $hidden))
        ->call('finish')
        ->assertHasErrors('stories')
        ->assertNoRedirect();

    expect($user->library()->count())->toBe(0)->and($user->fresh()->profile->onboarded_at)->toBeNull();
})->with([
    'an id that does not exist' => [fn ($good) => [$good[0]->id, $good[1]->id, 999999]],
    'a merged story' => [fn ($good, $merged) => [$good[0]->id, $good[1]->id, $merged->id]],
    'a gated story' => [fn ($good, $merged, $hidden) => [$good[0]->id, $good[1]->id, $hidden->id]],
    'text' => [fn ($good) => [$good[0]->id, $good[1]->id, 'abc']],
    'a nested value' => [fn ($good) => [$good[0]->id, $good[1]->id, [$good[2]->id]]],
    'a negative id' => [fn ($good) => [$good[0]->id, $good[1]->id, -1]],
    'the same story three times' => [fn ($good) => [$good[0]->id, $good[0]->id, $good[0]->id]],
    'far too many' => [fn () => range(1, 60)],
]);

test('a gated story is accepted once the reader may see mature titles', function () {
    onboardingTaxonomy();
    $good = onboardingStories(2);
    $hidden = matureStory();
    $this->actingAs($user = User::factory()->notOnboarded()->showsMature()->create());

    Livewire::test('pages::onboarding.index')->set('stories', [$good[0]->id, $good[1]->id, $hidden->id])->call('finish')->assertHasNoErrors()->assertRedirect(route('home'));

    expect($user->library()->count())->toBe(3);
});

// --- taking onboarding again -------------------------------------------------------------------------------------------------------------

test('taking onboarding again starts from what the reader chose before, and replaces formats and genres but never themes', function () {
    ['manga' => $manga, 'novel' => $novel, 'fantasy' => $fantasy, 'romance' => $romance] = onboardingTaxonomy();
    $theme = Theme::factory()->create(['slug' => 'revenge']);
    $stories = onboardingStories(3);
    $this->actingAs($user = User::factory()->create());
    UserPreference::factory()->for($user)->create(['preferable_type' => 'media_type', 'preferable_id' => $manga->id, 'weight' => 3]);
    UserPreference::factory()->for($user)->create(['preferable_type' => 'genre', 'preferable_id' => $fantasy->id]);
    UserPreference::factory()->for($user)->create(['preferable_type' => 'theme', 'preferable_id' => $theme->id]);

    Livewire::test('pages::onboarding.index')
        ->assertSet('mediaTypes', ['manga'])
        ->assertSet('genres', ['fantasy'])
        ->set('mediaTypes', ['manga', 'novel'])->set('genres', ['romance'])->set('stories', onboardingIds($stories))
        ->call('finish')
        ->assertRedirect(route('home'));

    $kept = $user->preferences()->where('preferable_id', $manga->id)->where('preferable_type', 'media_type')->sole();

    expect($user->preferences()->where('preferable_type', 'genre')->pluck('preferable_id')->all())->toBe([$romance->id])
        ->and($user->preferences()->where('preferable_type', 'media_type')->pluck('preferable_id')->sort()->values()->all())->toBe(collect([$manga->id, $novel->id])->sort()->values()->all())
        ->and($kept->weight)->toBe(3) // a weight that is already there is kept
        ->and($user->preferences()->where('preferable_type', 'theme')->count())->toBe(1);
});

test('stories already in the library are neither offered again nor changed by finishing again', function () {
    onboardingTaxonomy();
    $stories = onboardingStories(4);
    $this->actingAs($user = User::factory()->create());
    $reading = LibraryEntry::factory()->for($user)->for($stories[0])->status(ReadingStatus::Reading)->create();

    Livewire::test('pages::onboarding.index')
        ->set('stories', [$stories[0]->id, $stories[1]->id, $stories[2]->id])
        ->call('finish')
        ->assertHasNoErrors();

    expect($reading->fresh()->status)->toBe(ReadingStatus::Reading)
        ->and($user->library()->count())->toBe(3)
        ->and(UserInteraction::where('event', 'library_add')->count())->toBe(2);
});

// --- the table ---------------------------------------------------------------------------------------------------------------------------

test('a reader has a preference once: a second row for the same thing is refused by the database', function () {
    $user = User::factory()->create();
    $genre = Genre::factory()->create();

    UserPreference::factory()->for($user)->create(['preferable_type' => 'genre', 'preferable_id' => $genre->id]);

    // In a savepoint of its own: Postgres aborts the whole transaction on a violation, and the test runs inside one.
    expect(fn () => DB::transaction(fn () => UserPreference::factory()->for($user)->create(['preferable_type' => 'genre', 'preferable_id' => $genre->id])))->toThrow(UniqueConstraintViolationException::class);

    // The same id under another kind is another thing.
    UserPreference::factory()->for($user)->create(['preferable_type' => 'theme', 'preferable_id' => $genre->id]);

    expect($user->preferences()->count())->toBe(2);
});

test('deleting the reader deletes their preferences', function () {
    $user = User::factory()->create();
    UserPreference::factory()->for($user)->create();

    $user->delete();

    expect(UserPreference::count())->toBe(0);
});
