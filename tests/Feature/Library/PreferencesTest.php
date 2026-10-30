<?php

use App\Models\Creator;
use App\Models\Demographic;
use App\Models\Genre;
use App\Models\MediaType;
use App\Models\Profile;
use App\Models\Theme;
use App\Models\Trope;
use App\Models\User;
use App\Models\UserPreference;
use Database\Seeders\MediaTypeSeeder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/**
 * @return array{manga: MediaType, fantasy: Genre, isekai: Genre, mature: Genre, revenge: Theme, found-family: Theme}
 */
function preferenceTaxonomy(): array
{
    $fantasy = Genre::factory()->create(['name' => 'Fantasy', 'slug' => 'fantasy']);

    return [
        'manga' => MediaType::firstOrCreate(['slug' => 'manga'], MediaTypeSeeder::TYPES['manga']),
        'fantasy' => $fantasy,
        'isekai' => Genre::factory()->childOf($fantasy)->create(['name' => 'Isekai', 'slug' => 'isekai']),
        'mature' => Genre::factory()->mature()->create(['name' => 'Mature Family', 'slug' => 'mature-family']),
        'revenge' => Theme::factory()->create(['name' => 'Revenge', 'slug' => 'revenge']),
        'found-family' => Theme::factory()->create(['name' => 'Found Family', 'slug' => 'found-family']),
    ];
}

// --- the morph map and the table ---------------------------------------------------------------------------------------------------------

test('the morph map names the six things a preference can point at', function () {
    expect(Relation::morphMap())->toMatchArray([
        'genre' => Genre::class,
        'theme' => Theme::class,
        'trope' => Trope::class,
        'demographic' => Demographic::class,
        'media_type' => MediaType::class,
        'creator' => Creator::class,
    ]);
});

test('a preference stores the morph alias and resolves to the model', function () {
    $user = User::factory()->create();
    $genre = Genre::factory()->create();
    $creator = Creator::factory()->create();

    UserPreference::factory()->for($user)->create(['preferable_type' => 'genre', 'preferable_id' => $genre->id]);
    UserPreference::factory()->for($user)->create(['preferable_type' => 'creator', 'preferable_id' => $creator->id, 'weight' => 2]);

    expect(DB::table('user_preferences')->where('user_id', $user->id)->orderBy('id')->pluck('preferable_type')->all())->toBe(['genre', 'creator'])
        ->and($user->preferences()->with('preferable')->orderBy('id')->get()->map(fn (UserPreference $preference) => $preference->preferable::class)->all())->toBe([Genre::class, Creator::class])
        ->and($user->preferences()->where('preferable_type', 'creator')->sole()->weight)->toBe(2);
});

test('the user_preferences migration marks every existing profile onboarded, and gives readers who never saved a profile one that is', function () {
    $saved = User::factory()->create();
    $saved->profile()->updateOrCreate([], ['onboarded_at' => null, 'bio' => 'Kept bio']);
    $never = User::factory()->create();
    Profile::where('user_id', $never->id)->delete();
    $already = User::factory()->create();
    $already->profile()->updateOrCreate([], ['onboarded_at' => now()->subYear()]);
    $before = $already->profile()->value('onboarded_at');

    // The migration is a file that has run: take its table away (the test's transaction brings it back) and run it again.
    Schema::drop('user_preferences');
    (require database_path('migrations/2026_10_29_000007_create_user_preferences_table.php'))->up();

    expect(Schema::hasTable('user_preferences'))->toBeTrue()
        ->and(Profile::whereNull('onboarded_at')->count())->toBe(0)
        ->and($saved->profile()->first()->bio)->toBe('Kept bio')
        ->and($saved->profile()->first()->onboarded_at)->not->toBeNull()
        ->and($never->profile()->first()->onboarded_at)->not->toBeNull()
        ->and($never->profile()->first()->personalization_enabled)->toBeTrue()
        ->and($already->profile()->value('onboarded_at'))->toEqual($before);

    $this->actingAs($never->fresh())->get(route('home'))->assertOk();
});

// --- Settings, Preferences ---------------------------------------------------------------------------------------------------------------

test('the preferences page is a settings page that guides the reader and links to retake onboarding', function () {
    preferenceTaxonomy();

    $html = $this->actingAs(User::factory()->create())->get(route('preferences.edit'))->assertOk()->getContent();

    expect(trim(htmlQuery($html, '//h1')->item(0)->textContent))->toBe('Preferences')
        ->and(trim(htmlQuery($html, '(//h1)[1]/following::p[1]')->item(0)->textContent))->not->toBe('')
        ->and(htmlQuery($html, '//a[@href="'.route('onboarding').'"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//nav[@aria-label="Settings"]//a[@href="'.route('preferences.edit').'"][@aria-current="page"]')->length)->toBe(1);
});

test('a guest is sent to sign in and an unverified reader to verification', function () {
    $this->get(route('preferences.edit'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())->get(route('preferences.edit'))->assertRedirect('/email/verify');
});

test('the page offers the formats, the genre families with their subgenres, and the themes', function () {
    preferenceTaxonomy();
    $this->actingAs(User::factory()->create());

    $html = Livewire::test('pages::settings.preferences')->html();

    foreach (['manga', 'fantasy', 'isekai', 'revenge', 'found-family'] as $value) {
        expect(htmlQuery($html, '//input[@type="checkbox"][@value="'.$value.'"]')->length)->toBe(1, $value);
    }

    expect(htmlQuery($html, '//input[@value="mature-family"]')->length)->toBe(0);
});

test('the mature family is offered only to a reader who may see it', function () {
    preferenceTaxonomy();

    $this->actingAs(User::factory()->showsMature()->create());

    expect(htmlQuery(Livewire::test('pages::settings.preferences')->html(), '//input[@type="checkbox"][@value="mature-family"]')->length)->toBe(1);
});

test('saving writes formats, genres and themes with the morph aliases and weight 1', function () {
    $taxonomy = preferenceTaxonomy();
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::settings.preferences')
        ->set('mediaTypes', ['manga'])->set('genres', ['fantasy', 'isekai'])->set('themes', ['revenge'])
        ->call('save')
        ->assertHasNoErrors();

    expect($user->preferences()->count())->toBe(4)
        ->and($user->preferences()->where('preferable_type', 'media_type')->sole()->preferable)->toBeInstanceOf(MediaType::class)
        ->and($user->preferences()->where('preferable_type', 'genre')->get()->pluck('preferable_id')->sort()->values()->all())->toBe(collect([$taxonomy['fantasy']->id, $taxonomy['isekai']->id])->sort()->values()->all())
        ->and($user->preferences()->where('preferable_type', 'theme')->sole()->preferable)->toBeInstanceOf(Theme::class)
        ->and($user->preferences()->pluck('weight')->unique()->all())->toBe([1])
        ->and(DB::table('user_preferences')->where('user_id', $user->id)->distinct()->pluck('preferable_type')->sort()->values()->all())->toBe(['genre', 'media_type', 'theme']);
});

test('the page starts from what the reader chose, and saving fewer choices removes the others', function () {
    $taxonomy = preferenceTaxonomy();
    $this->actingAs($user = User::factory()->create());
    UserPreference::factory()->for($user)->create(['preferable_type' => 'genre', 'preferable_id' => $taxonomy['fantasy']->id, 'weight' => 4]);
    UserPreference::factory()->for($user)->create(['preferable_type' => 'genre', 'preferable_id' => $taxonomy['isekai']->id]);
    UserPreference::factory()->for($user)->create(['preferable_type' => 'theme', 'preferable_id' => $taxonomy['revenge']->id]);

    Livewire::test('pages::settings.preferences')
        ->assertSet('genres', fn (array $genres) => collect($genres)->sort()->values()->all() === ['fantasy', 'isekai'])
        ->assertSet('themes', ['revenge'])
        ->assertSet('mediaTypes', [])
        ->set('genres', ['fantasy'])->set('themes', [])
        ->call('save');

    expect($user->preferences()->count())->toBe(1)
        ->and($user->preferences()->sole()->weight)->toBe(4);
});

test('unknown slugs, ids and nested values are refused and nothing is saved', function (string $property, array $value) {
    preferenceTaxonomy();
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::settings.preferences')->set($property, $value)->call('save')->assertHasErrors();

    expect($user->preferences()->count())->toBe(0);
})->with([
    'a genre that does not exist' => ['genres', ['nope']],
    'a format that does not exist' => ['mediaTypes', ['nope']],
    'a theme that does not exist' => ['themes', ['nope']],
    'a genre id' => ['genres', [1]],
    'a nested value' => ['themes', [['revenge']]],
    'a genre as a theme' => ['themes', ['fantasy']],
]);

test('a reader who may not see mature titles cannot save the mature family, and keeps one they chose while they could', function () {
    $taxonomy = preferenceTaxonomy();
    $this->actingAs($user = User::factory()->showsMature()->create());
    UserPreference::factory()->for($user)->create(['preferable_type' => 'genre', 'preferable_id' => $taxonomy['mature']->id]);

    // Turned off since: the page neither offers the family nor starts with it, and a save does not drop it.
    $user->profile()->updateOrCreate([], ['show_mature' => false, 'adult_confirmed_at' => null]);
    $user->unsetRelation('profile');

    Livewire::test('pages::settings.preferences')
        ->assertSet('genres', [])
        ->set('genres', ['fantasy'])->call('save')->assertHasNoErrors()
        ->set('genres', ['mature-family'])->call('save')->assertHasErrors('genres.0');

    expect($user->preferences()->where('preferable_id', $taxonomy['mature']->id)->where('preferable_type', 'genre')->exists())->toBeTrue()
        ->and($user->preferences()->where('preferable_id', $taxonomy['fantasy']->id)->where('preferable_type', 'genre')->exists())->toBeTrue();
});

test('saving changes the signed in reader only', function () {
    $taxonomy = preferenceTaxonomy();
    $other = User::factory()->create();
    UserPreference::factory()->for($other)->create(['preferable_type' => 'genre', 'preferable_id' => $taxonomy['fantasy']->id]);
    $this->actingAs($user = User::factory()->create());

    Livewire::test('pages::settings.preferences')->set('genres', ['isekai'])->call('save');
    Livewire::test('pages::settings.preferences')->set('genres', [])->call('save');

    expect($other->preferences()->count())->toBe(1)->and($user->preferences()->count())->toBe(0);
});

test('saving does not need the reader to have onboarded, and does not onboard them', function () {
    preferenceTaxonomy();
    $this->actingAs($user = User::factory()->notOnboarded()->create());

    Livewire::test('pages::settings.preferences')->set('genres', ['fantasy'])->call('save')->assertHasNoErrors();

    expect($user->preferences()->count())->toBe(1)->and($user->fresh()->profile->onboarded_at)->toBeNull();
});
