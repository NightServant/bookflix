<?php

use App\Enums\InteractionEvent;
use App\Enums\ReadingStatus;
use App\Jobs\ExportUserData;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Creator;
use App\Models\Genre;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\MediaType;
use App\Models\Rating;
use App\Models\ReadingProgress;
use App\Models\ReadingSession;
use App\Models\SearchQuery;
use App\Models\Theme;
use App\Models\User;
use App\Models\UserInteraction;
use App\Models\UserPreference;
use App\Notifications\DataExportReady;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

const EXPORT_FILES = ['profile.json', 'preferences.json', 'library.json', 'collections.json', 'ratings.json', 'progress.json', 'history.json', 'interactions.json', 'searches.json'];

/**
 * A reader with one row in every table the export reads, one of them behind the mature gate.
 */
function exportFixture(string $label = 'Mine'): User
{
    $user = User::factory()->create(['name' => "{$label} Reader", 'email' => strtolower($label).'-reader@example.test']);
    $user->profile()->updateOrCreate([], ['bio' => "{$label} bio", 'avatar_path' => "avatars/{$user->id}/secret-name.jpg"]);
    UserPreference::factory()->for($user)->create(['preferable_type' => 'genre', 'preferable_id' => Genre::factory()->create(['name' => "{$label} Genre", 'slug' => strtolower($label).'-genre'])->id]);

    $story = Media::factory()->create(['title' => "{$label} Story"]);
    $gated = matureStory("{$label} Gated Tale");

    LibraryEntry::factory()->for($user)->for($story)->status(ReadingStatus::Reading)->favorite()->create();
    LibraryEntry::factory()->for($user)->for($gated)->create();
    $collection = Collection::factory()->for($user)->create(['name' => "{$label} Shelf", 'description' => 'Kept close']);
    CollectionItem::factory()->for($collection)->for($story)->create(['position' => 0, 'note' => 'Start here']);
    CollectionItem::factory()->for($collection)->for($gated)->create(['position' => 1]);
    Rating::factory()->create(['user_id' => $user->id, 'media_id' => $story->id, 'score' => 9]);
    Rating::factory()->create(['user_id' => $user->id, 'media_id' => $gated->id, 'score' => 3]);
    ReadingProgress::factory()->create(['user_id' => $user->id, 'media_id' => $story->id, 'percent' => 42.5, 'chapter' => '7', 'page' => 120]);
    ReadingSession::factory()->create(['user_id' => $user->id, 'media_id' => $story->id, 'seconds' => 1800, 'from_percent' => 30, 'to_percent' => 42.5]);
    ReadingSession::factory()->create(['user_id' => $user->id, 'media_id' => $gated->id]);
    UserInteraction::factory()->create(['user_id' => $user->id, 'media_id' => $story->id, 'event' => InteractionEvent::Rating, 'value' => 9, 'context' => ['source' => 'controls']]);
    SearchQuery::factory()->create(['user_id' => $user->id, 'query' => "{$label} search", 'filters' => ['genres' => ["{$label}-genre"]], 'results_count' => 7]);

    return $user;
}

/**
 * The zip files of this reader's export on the fake disk.
 *
 * @return list<string>
 */
function exportPaths(User $user): array
{
    return Storage::disk('local')->allFiles("exports/{$user->id}");
}

/**
 * What an export zip holds: file name to decoded JSON.
 *
 * @return array<string, mixed>
 */
function exportContents(User $user): array
{
    $path = exportPaths($user)[0] ?? throw new RuntimeException('No export was written.');
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($path));
    $contents = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $contents[$zip->getNameIndex($i)] = json_decode($zip->getFromIndex($i), true, flags: JSON_THROW_ON_ERROR);
    }

    $zip->close();

    return $contents;
}

/**
 * The raw text of the export zip's files, for checks that nothing of another reader is in it.
 */
function exportText(User $user): string
{
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path(exportPaths($user)[0]));

    return collect(range(0, $zip->numFiles - 1))->map(fn (int $i): string => $zip->getFromIndex($i))->implode("\n");
}

function exportUlid(User $user): string
{
    return pathinfo(exportPaths($user)[0], PATHINFO_FILENAME);
}

function exportLink(User $user, ?string $file = null, int $hours = 24): string
{
    return URL::temporarySignedRoute('exports.download', now()->addHours($hours), ['user' => $user->id, 'file' => $file ?? exportUlid($user)]);
}

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
});

test('the export zip holds the nine files, with every one of the readers own rows and nothing else', function () {
    $user = exportFixture('Mine');
    $other = exportFixture('Theirs');

    (new ExportUserData($user->id))->handle();

    $files = exportContents($user);
    expect(array_keys($files))->toEqualCanonicalizing(EXPORT_FILES)
        ->and($files['library.json'])->toHaveCount(2)
        ->and($files['collections.json'])->toHaveCount(1)
        ->and($files['ratings.json'])->toHaveCount(2)
        ->and($files['progress.json'])->toHaveCount(1)
        ->and($files['history.json'])->toHaveCount(2)
        ->and($files['interactions.json'])->toHaveCount(1)
        ->and($files['searches.json'])->toHaveCount(1)
        ->and($files['preferences.json'])->toBe([['type' => 'genre', 'slug' => 'mine-genre', 'name' => 'Mine Genre', 'weight' => 1]]);

    $text = exportText($user);
    expect($text)->not->toContain('Theirs')->not->toContain($other->email)->not->toContain("avatars/{$other->id}")
        ->and(exportPaths($other))->toBe([]);
});

test('the export holds the rows behind the mature gate too', function () {
    $user = exportFixture();

    (new ExportUserData($user->id))->handle();

    $files = exportContents($user);
    expect(collect($files['library.json'])->pluck('title')->all())->toContain('Mine Gated Tale')
        ->and(collect($files['ratings.json'])->pluck('title')->all())->toContain('Mine Gated Tale')
        ->and(collect($files['collections.json'][0]['items'])->pluck('title')->all())->toContain('Mine Gated Tale')
        ->and(collect($files['history.json'])->pluck('title')->all())->toContain('Mine Gated Tale');
});

test('the rows say what they are: stories by title, in the readers terms', function () {
    $user = exportFixture();
    $story = Media::where('title', 'Mine Story')->sole();

    (new ExportUserData($user->id))->handle();

    $files = exportContents($user);
    $entry = collect($files['library.json'])->firstWhere('title', 'Mine Story');
    $collection = $files['collections.json'][0];
    $rating = collect($files['ratings.json'])->firstWhere('title', 'Mine Story');
    $progress = $files['progress.json'][0];
    $session = collect($files['history.json'])->firstWhere('title', 'Mine Story');
    $interaction = $files['interactions.json'][0];

    expect($entry)->toMatchArray(['media_id' => $story->id, 'slug' => $story->slug, 'status' => 'reading', 'favorite' => true])
        ->and($entry['added_at'])->toBeString()->and($entry['favorited_at'])->toBeString()
        ->and($collection)->toMatchArray(['name' => 'Mine Shelf', 'description' => 'Kept close', 'visibility' => 'private'])
        ->and(collect($collection['items'])->pluck('title')->all())->toBe(['Mine Story', 'Mine Gated Tale'])
        ->and($collection['items'][0])->toMatchArray(['position' => 0, 'note' => 'Start here', 'media_id' => $story->id])
        ->and($rating)->toMatchArray(['media_id' => $story->id, 'score' => 9])
        ->and($progress)->toMatchArray(['title' => 'Mine Story', 'percent' => 42.5, 'chapter' => '7', 'page' => 120])
        ->and($session)->toMatchArray(['seconds' => 1800, 'from_percent' => 30.0, 'to_percent' => 42.5])
        ->and($interaction)->toMatchArray(['event' => 'rating', 'value' => 9.0, 'media_id' => $story->id, 'context' => ['source' => 'controls']]);
});

test('the profile file has the account and profile, and none of the secrets', function () {
    $user = exportFixture();
    $user->forceFill(['two_factor_secret' => 'SECRETSECRET', 'two_factor_recovery_codes' => 'RECOVERY', 'remember_token' => 'REMEMBERME'])->save();

    (new ExportUserData($user->id))->handle();

    $profile = exportContents($user)['profile.json'];
    $text = exportText($user);

    expect($profile['account'])->toMatchArray(['id' => $user->id, 'name' => 'Mine Reader', 'email' => 'mine-reader@example.test'])
        ->and($profile['profile'])->toMatchArray(['bio' => 'Mine bio', 'profile_visibility' => 'private', 'personalization_enabled' => true, 'has_avatar' => true])
        ->and($profile['exported_at'])->toBeString()
        ->and($text)->not->toContain('SECRETSECRET')->not->toContain('RECOVERY')->not->toContain('REMEMBERME')->not->toContain($user->password)->not->toContain('secret-name.jpg');
});

test('a reader with nothing yet still gets all nine files', function () {
    $user = User::factory()->create();

    (new ExportUserData($user->id))->handle();

    $files = exportContents($user);
    expect(array_keys($files))->toEqualCanonicalizing(EXPORT_FILES)
        ->and($files['profile.json']['account']['email'])->toBe($user->email);

    foreach (array_diff(EXPORT_FILES, ['profile.json']) as $name) {
        expect($files[$name])->toBe([]);
    }
});

test('searches are the readers own queries with their filters, result counts and times, and nothing that ties them to a session', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $first = SearchQuery::factory()->create(['user_id' => $user->id, 'query' => 'dragon slayer', 'normalized' => 'dragon slayer', 'filters' => ['genres' => ['fantasy'], 'minRating' => '4'], 'results_count' => 12, 'session_hash' => str_repeat('a', 64)]);
    $second = SearchQuery::factory()->create(['user_id' => $user->id, 'query' => 'nothing found', 'filters' => [], 'results_count' => 0, 'previous_query_id' => $first->id]);
    SearchQuery::factory()->create(['user_id' => $other->id, 'query' => 'their private search', 'session_hash' => str_repeat('b', 64)]);
    SearchQuery::factory()->create(['user_id' => null, 'query' => 'an opted out search']);
    DB::table('search_queries')->where('id', $first->id)->update(['created_at' => '2026-10-01 08:30:00']);

    (new ExportUserData($user->id))->handle();

    $searches = exportContents($user)['searches.json'];

    expect($searches)->toHaveCount(2)
        ->and(array_keys($searches[0]))->toBe(['query', 'filters', 'results_count', 'created_at'])
        ->and($searches[0])->toBe(['query' => 'dragon slayer', 'filters' => ['genres' => ['fantasy'], 'minRating' => '4'], 'results_count' => 12, 'created_at' => '2026-10-01T08:30:00+00:00'])
        ->and($searches[1]['query'])->toBe('nothing found')->and($searches[1]['results_count'])->toBe(0)->and($searches[1]['filters'])->toBe([])
        ->and(exportText($user))->not->toContain('their private search')->not->toContain('an opted out search')
        ->not->toContain(str_repeat('a', 64))->not->toContain('session_hash')->not->toContain('previous_query_id');
});

test('another reader\'s searches never appear in an export, in either direction', function () {
    $mine = exportFixture('Mine');
    $theirs = exportFixture('Theirs');

    (new ExportUserData($mine->id))->handle();
    (new ExportUserData($theirs->id))->handle();

    expect(collect(exportContents($mine)['searches.json'])->pluck('query')->all())->toBe(['Mine search'])
        ->and(collect(exportContents($theirs)['searches.json'])->pluck('query')->all())->toBe(['Theirs search'])
        ->and(exportText($mine))->not->toContain('Theirs search')
        ->and(exportText($theirs))->not->toContain('Mine search');
});

test('a long history is written in pieces and still reads back whole', function () {
    $user = User::factory()->create();
    $story = Media::factory()->create();
    $rows = collect(range(1, 1250))->map(fn (int $i): array => ['user_id' => $user->id, 'media_id' => $story->id, 'event' => 'view', 'context' => '{}', 'occurred_at' => now()->subMinutes($i)])->all();

    foreach (array_chunk($rows, 500) as $chunk) {
        UserInteraction::insert($chunk);
    }

    (new ExportUserData($user->id))->handle();

    expect(exportContents($user)['interactions.json'])->toHaveCount(1250);
});

test('preferences are labelled rows: the kind, slug, name and weight of what each one points at, and only the readers own', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $genre = Genre::factory()->create(['name' => 'Isekai', 'slug' => 'isekai']);
    $mature = Genre::factory()->mature()->create(['name' => 'Mature Family', 'slug' => 'mature-family']);
    $theme = Theme::factory()->create(['name' => 'Found Family', 'slug' => 'found-family']);
    $type = MediaType::factory()->create(['name' => 'Manga', 'slug' => 'manga']);
    $creator = Creator::factory()->create(['name' => 'Naoki Urasawa', 'slug' => 'naoki-urasawa']);
    $gone = Genre::factory()->create();

    foreach ([['media_type', $type->id, 3], ['genre', $genre->id, 1], ['genre', $mature->id, 1], ['theme', $theme->id, 2], ['creator', $creator->id, 1], ['genre', $gone->id, 1]] as [$kind, $id, $weight]) {
        UserPreference::factory()->for($user)->create(['preferable_type' => $kind, 'preferable_id' => $id, 'weight' => $weight]);
    }

    UserPreference::factory()->for($other)->create(['preferable_type' => 'genre', 'preferable_id' => Genre::factory()->create(['name' => 'Someone Elses', 'slug' => 'someone-elses'])->id]);
    $gone->delete();

    (new ExportUserData($user->id))->handle();

    $preferences = collect(exportContents($user)['preferences.json']);

    expect($preferences)->toHaveCount(6)
        ->and($preferences->map(fn (array $row) => array_keys($row))->unique()->all())->toBe([['type', 'slug', 'name', 'weight']])
        ->and($preferences->firstWhere('type', 'media_type'))->toBe(['type' => 'media_type', 'slug' => 'manga', 'name' => 'Manga', 'weight' => 3])
        ->and($preferences->firstWhere('slug', 'isekai'))->toBe(['type' => 'genre', 'slug' => 'isekai', 'name' => 'Isekai', 'weight' => 1])
        ->and($preferences->firstWhere('slug', 'mature-family'))->not->toBeNull() // behind the gate, still the reader's own
        ->and($preferences->firstWhere('type', 'theme'))->toBe(['type' => 'theme', 'slug' => 'found-family', 'name' => 'Found Family', 'weight' => 2])
        ->and($preferences->firstWhere('type', 'creator'))->toBe(['type' => 'creator', 'slug' => 'naoki-urasawa', 'name' => 'Naoki Urasawa', 'weight' => 1])
        ->and($preferences->firstWhere('slug', null))->toBe(['type' => 'genre', 'slug' => null, 'name' => null, 'weight' => 1]) // its genre was deleted since
        ->and(exportText($user))->not->toContain('Someone Elses')->not->toContain('someone-elses');
});

test('the reader is mailed a link that expires after 24 hours', function () {
    $user = exportFixture();

    (new ExportUserData($user->id))->handle();

    Notification::assertSentTo($user, DataExportReady::class, function (DataExportReady $notification) use ($user): bool {
        $mail = $notification->toMail($user);
        $parts = parse_url($mail->actionUrl);
        parse_str($parts['query'], $query);

        return in_array('mail', $notification->via($user), true)
            && count($notification->via($user)) === 1
            && str_contains($mail->actionUrl, "/exports/{$user->id}/".exportUlid($user))
            && abs((int) $query['expires'] - now()->addHours(24)->timestamp) <= 5
            && URL::hasValidSignature(Request::create($mail->actionUrl));
    });
    Notification::assertCount(1);
});

test('the signed link downloads the zip for its owner and only as a private download', function () {
    $user = exportFixture();
    (new ExportUserData($user->id))->handle();

    $response = $this->actingAs($user)->get(exportLink($user))->assertOk()->assertDownload();

    expect($response->headers->get('Content-Type'))->toContain('zip')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('Content-Disposition'))->toContain('bookflix-export');
});

test('another reader, a guest, an unsigned, a tampered and an expired link are all refused', function () {
    $user = exportFixture();
    (new ExportUserData($user->id))->handle();
    $file = exportUlid($user);
    $url = exportLink($user);

    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    $this->actingAs($user)->get(route('exports.download', ['user' => $user->id, 'file' => $file]))->assertForbidden();
    $this->actingAs($user)->get(str_replace("/exports/{$user->id}/", '/exports/'.($user->id + 1).'/', $url))->assertForbidden();
    $this->actingAs($user)->get($url.'0')->assertForbidden();

    $this->travel(25)->hours();
    $this->actingAs($user)->get($url)->assertForbidden();
});

test('a guest is sent to sign in, not given the file', function () {
    $user = exportFixture();
    (new ExportUserData($user->id))->handle();

    $this->get(exportLink($user))->assertRedirect(route('login'));
});

test('a link signed for the owners id cannot reach another readers file', function () {
    $user = exportFixture('Mine');
    $other = exportFixture('Theirs');
    (new ExportUserData($user->id))->handle();
    (new ExportUserData($other->id))->handle();

    // The owner signs a path of their own directory with the other reader's file name: the file is not there.
    $this->actingAs($user)->get(exportLink($user, exportUlid($other)))->assertNotFound();
    // And the other reader cannot use the owner's link even though it is signed.
    $this->actingAs($other)->get(exportLink($user))->assertForbidden();
});

test('a file name that is not a ulid is never looked up', function (string $file) {
    $user = User::factory()->create();
    Storage::disk('local')->put('.env', 'APP_KEY=secret');

    $this->actingAs($user)->get(exportLink($user, $file))->assertNotFound();
})->with([
    'parent directories' => ['../../.env'],
    'encoded parent directories' => ['..%2F..%2F.env'],
    'a sub path' => ['01ARZ3NDEKTSV4RRFFQ69G5FA/x'],
    'too short' => ['01ARZ3NDEKTSV4RRFFQ69G5FA'],
    'too long' => ['01ARZ3NDEKTSV4RRFFQ69G5FAVV'],
    'lower case' => ['01arz3ndektsv4rrffq69g5fav'],
    'with the extension' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV.zip'],
]);

test('only a ulid names an export: a zip kept under any other name is not served', function (string $name) {
    $user = User::factory()->create();
    Storage::disk('local')->put("exports/{$user->id}/{$name}.zip", 'x');

    $this->actingAs($user)->get(exportLink($user, $name))->assertNotFound();
})->with(['a plain name' => 'notes', 'a lower case ulid' => '01arz3ndektsv4rrffq69g5fav', 'a short ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FA']);

test('a user segment that is not a number is never looked up', function () {
    $user = User::factory()->create();
    $url = URL::temporarySignedRoute('exports.download', now()->addHour(), ['user' => 'abc', 'file' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']);

    $this->actingAs($user)->get($url)->assertNotFound();
});

test('a correctly signed link to a file that is gone is a 404', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(exportLink($user, '01ARZ3NDEKTSV4RRFFQ69G5FAV'))->assertNotFound();
});

test('the zip is written to a private disk, and a public disk is refused', function () {
    $user = User::factory()->create();

    (new ExportUserData($user->id))->handle();

    expect(config('filesystems.disks.local.visibility'))->not->toBe('public')
        ->and(exportPaths($user))->toHaveCount(1)
        ->and(exportPaths($user)[0])->toMatch('#^exports/'.$user->id.'/[0-9A-Z]{26}\.zip$#');

    Storage::fake('public');
    config(['bookflix.exports.disk' => 'public']);

    expect(fn () => (new ExportUserData($user->id))->handle())->toThrow(RuntimeException::class);
    expect(Storage::disk('public')->allFiles())->toBe([]);
    Notification::assertCount(1);
});

test('a new export replaces the readers older one and leaves everyone elses alone', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    (new ExportUserData($other->id))->handle();
    (new ExportUserData($user->id))->handle();
    $first = exportUlid($user);

    (new ExportUserData($user->id))->handle();

    expect(exportPaths($user))->toHaveCount(1)->and(exportUlid($user))->not->toBe($first)
        ->and(exportPaths($other))->toHaveCount(1);
});

test('a deleted, deactivated or unverified account is not exported and nobody is mailed', function () {
    $deactivated = User::factory()->deactivated()->create();
    $unverified = User::factory()->unverified()->create();

    (new ExportUserData($deactivated->id))->handle();
    (new ExportUserData($unverified->id))->handle();
    (new ExportUserData(999999))->handle();

    expect(Storage::disk('local')->allFiles())->toBe([]);
    Notification::assertNothingSent();
});

test('an export that cannot be stored mails nobody', function () {
    $user = exportFixture();
    config(['bookflix.exports.disk' => 'not-a-disk']);

    expect(fn () => (new ExportUserData($user->id))->handle())->toThrow(InvalidArgumentException::class);

    Notification::assertNothingSent();
});

test('the privacy page button queues the export for the signed in reader, once every ten minutes', function () {
    Queue::fake();
    $this->actingAs($user = User::factory()->create());

    $page = Livewire::test('pages::settings.privacy')->call('exportData')->assertHasNoErrors();

    Queue::assertPushed(ExportUserData::class, 1);
    Queue::assertPushed(ExportUserData::class, fn (ExportUserData $job): bool => $job->userId === $user->id);
    $page->assertSee('Preparing your export')->assertSee($user->email)->assertSee('You can ask for another in 10 minutes');

    $page->call('exportData');
    Queue::assertPushed(ExportUserData::class, 1);

    $this->travel(9)->minutes();
    Livewire::test('pages::settings.privacy')->call('exportData');
    Queue::assertPushed(ExportUserData::class, 1);

    $this->travel(2)->minutes();
    Livewire::test('pages::settings.privacy')->call('exportData');
    Queue::assertPushed(ExportUserData::class, 2);
});

test('one readers request does not use up anothers', function () {
    Queue::fake();
    $first = User::factory()->create();
    $second = User::factory()->create();

    $this->actingAs($first);
    Livewire::test('pages::settings.privacy')->call('exportData');
    $this->actingAs($second);
    Livewire::test('pages::settings.privacy')->call('exportData');

    Queue::assertPushed(ExportUserData::class, 2);
    Queue::assertPushed(ExportUserData::class, fn (ExportUserData $job): bool => $job->userId === $second->id);
});

test('the export cannot be asked for on behalf of another reader', function () {
    Queue::fake();
    $this->actingAs($user = User::factory()->create());
    $victim = User::factory()->create();

    // The action takes no id; a crafted property or argument changes nothing.
    Livewire::test('pages::settings.privacy')->call('exportData', $victim->id);

    Queue::assertPushed(ExportUserData::class, fn (ExportUserData $job): bool => $job->userId === $user->id);
    Queue::assertNotPushed(ExportUserData::class, fn (ExportUserData $job): bool => $job->userId === $victim->id);
});

test('the page says how the export stands: idle, being prepared, and ready with a download link', function () {
    Queue::fake();
    $this->actingAs($user = User::factory()->create());

    $idle = Livewire::test('pages::settings.privacy');
    $idle->assertSee('Export my data')->assertDontSee('Preparing your export')->assertDontSee('Your export is ready');
    expect(htmlQuery($idle->html(), '//button[@data-test="export-data-button"][@disabled]')->length)->toBe(0);

    $idle->call('exportData');
    $preparing = Livewire::test('pages::settings.privacy');
    $preparing->assertSee('Preparing your export');
    expect(htmlQuery($preparing->html(), '//button[@data-test="export-data-button"][@disabled]')->length)->toBe(1)
        ->and(htmlQuery($preparing->html(), '//*[@role="status"][contains(.,"Preparing your export")]')->length)->toBe(1);

    (new ExportUserData($user->id))->handle();

    $ready = Livewire::test('pages::settings.privacy');
    $ready->assertSee('Your export is ready')->assertDontSee('Preparing your export');
    $link = htmlQuery($ready->html(), '//a[@data-test="export-download-link"]');
    expect($link->length)->toBe(1);

    $this->get(html_entity_decode($link->item(0)->getAttribute('href')))->assertOk()->assertDownload();
});

test('an export from before the last request is not shown as the one being prepared', function () {
    Queue::fake();
    $this->actingAs($user = User::factory()->create());
    (new ExportUserData($user->id))->handle();
    $this->travel(11)->minutes();

    $page = Livewire::test('pages::settings.privacy');
    $page->assertSee('Your export is ready');
    $page->call('exportData');
    $page = Livewire::test('pages::settings.privacy');

    $page->assertSee('Preparing your export')->assertSee('Your previous export');
    expect(htmlQuery($page->html(), '//a[@data-test="export-download-link"]')->length)->toBe(1);
});

test('the download link on the page is for the signed in reader only', function () {
    $this->actingAs($user = exportFixture('Mine'));
    $other = exportFixture('Theirs');
    (new ExportUserData($user->id))->handle();
    (new ExportUserData($other->id))->handle();

    $html = Livewire::test('pages::settings.privacy')->html();

    expect($html)->toContain("exports/{$user->id}/".exportUlid($user))->not->toContain("exports/{$other->id}/".exportUlid($other));
});

test('old exports are pruned, recent ones and other files are kept', function () {
    $disk = Storage::disk('local');
    $disk->put('exports/1/old.zip', 'x');
    $disk->put('exports/1/new.zip', 'x');
    $disk->put('exports/2/older.zip', 'x');
    $disk->put('covers/keep.jpg', 'x');
    touch($disk->path('exports/1/old.zip'), now()->subDays(8)->timestamp);
    touch($disk->path('exports/2/older.zip'), now()->subDays(30)->timestamp);
    touch($disk->path('exports/1/new.zip'), now()->subDays(6)->timestamp);
    touch($disk->path('covers/keep.jpg'), now()->subDays(60)->timestamp);

    $this->artisan('bookflix:prune-exports')->assertSuccessful();

    $disk->assertMissing('exports/1/old.zip');
    $disk->assertMissing('exports/2/older.zip');
    $disk->assertExists('exports/1/new.zip');
    $disk->assertExists('covers/keep.jpg');
    expect($disk->directoryExists('exports/2'))->toBeFalse()->and($disk->directoryExists('exports/1'))->toBeTrue();
});

test('pruning is scheduled daily', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'bookflix:prune-exports'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 0 * * *');
});

test('purging an account deletes its exports with it', function () {
    Storage::fake('public');
    $user = exportFixture();
    (new ExportUserData($user->id))->handle();
    $survivor = User::factory()->create();
    (new ExportUserData($survivor->id))->handle();
    DB::table('users')->where('id', $user->id)->update(['deactivated_at' => now()->subDays(31)]);

    $this->artisan('bookflix:purge-deactivated-accounts')->assertSuccessful();

    expect(Storage::disk('local')->directoryExists("exports/{$user->id}"))->toBeFalse()
        ->and(exportPaths($survivor))->toHaveCount(1);
});

test('the download route is the only way in: nothing serves the private disk', function () {
    $user = User::factory()->create();
    (new ExportUserData($user->id))->handle();
    $path = exportPaths($user)[0];

    expect($this->actingAs($user)->get('/storage/'.$path)->status())->toBeIn([403, 404]);
    $this->actingAs($user)->get('/'.$path)->assertNotFound();
    $this->actingAs($user)->get('/exports/'.$user->id)->assertNotFound();
});

test('the download is throttled per reader, not shared with other numeric throttles', function () {
    $user = User::factory()->create();
    (new ExportUserData($user->id))->handle();
    $route = app('router')->getRoutes()->getByName('exports.download');

    expect($route->gatherMiddleware())->toContain('auth', 'signed')
        ->and(collect($route->gatherMiddleware())->first(fn ($m) => str_starts_with((string) $m, 'throttle:')))->toBe('throttle:30,1,exports');
});
