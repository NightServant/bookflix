<?php

use App\Models\Collection;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\Series;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
 * An admin account reaches the admin pages and its own account pages, and nothing of the reader side (see EnsureReaderAccount).
 */

beforeEach(function () {
    $this->seed(DeweySeeder::class);
    Http::fake(['*/health' => Http::response(['status' => 'ok'])]);
});

dataset('reader pages', [
    'home' => ['home', fn () => []],
    'onboarding' => ['onboarding', fn () => []],
    'search' => ['search', fn () => []],
    'discover' => ['discover', fn () => []],
    'explore.index' => ['explore.index', fn () => []],
    'explore.show' => ['explore.show', fn () => ['code' => '813']],
    'stories.show' => ['stories.show', fn () => ['slug' => Media::factory()->create()->slug]],
    'series.show' => ['series.show', fn () => ['slug' => Series::factory()->create()->slug]],
    'library.index' => ['library.index', fn () => []],
    'collections.index' => ['collections.index', fn () => []],
    'collections.show' => ['collections.show', fn () => ['slug' => Collection::factory()->create(['visibility' => 'public'])->slug]],
    'history.index' => ['history.index', fn () => []],
    'preferences.edit' => ['preferences.edit', fn () => []],
    'privacy.edit' => ['privacy.edit', fn () => []],
]);

dataset('reader writes', [
    'library.store' => ['post', 'library.store', fn () => [], fn () => ['media_id' => Media::factory()->create()->id]],
    'recommendations.click' => ['post', 'recommendations.click', fn () => [], fn () => ['recommendation_id' => 1]],
    'recommendations.impressions' => ['post', 'recommendations.impressions', fn () => [], fn () => ['ids' => [1]]],
    'recommendations.dismiss' => ['post', 'recommendations.dismiss', fn () => ['recommendation' => 1], fn () => []],
    'recommendations.undo' => ['delete', 'recommendations.undo', fn () => ['recommendation' => 1], fn () => []],
]);

dataset('admin pages', [
    'admin.index' => ['admin.index', fn () => []],
    'admin.providers' => ['admin.providers', fn () => []],
    'admin.media.index' => ['admin.media.index', fn () => []],
    'admin.media.edit' => ['admin.media.edit', fn () => ['media' => Media::factory()->create()]],
    'admin.duplicates' => ['admin.duplicates', fn () => []],
    'admin.classifications' => ['admin.classifications', fn () => []],
    'admin.models' => ['admin.models', fn () => []],
    'admin.users' => ['admin.users', fn () => []],
    'profile.edit' => ['profile.edit', fn () => []],
    'security.edit' => ['security.edit', fn () => []],
]);

test('an admin is sent to the admin dashboard from every reader page', function (string $name, Closure $parameters) {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route($name, $parameters()))
        ->assertRedirect(route('admin.index'));
})->with('reader pages');

test('a reader still gets every reader page', function (string $name, Closure $parameters) {
    $response = $this->actingAs(User::factory()->create())->get(route($name, $parameters()));

    $response->assertOk();
})->with('reader pages');

test('an admin is refused with a 403, not a redirect, on every reader write', function (string $method, string $name, Closure $parameters, Closure $payload) {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->{$method}(route($name, $parameters()), $payload())->assertForbidden();

    expect(LibraryEntry::count())->toBe(0);
})->with('reader writes');

test('a reader reaches every reader write', function (string $method, string $name, Closure $parameters, Closure $payload) {
    $response = $this->actingAs(User::factory()->create())->{$method}(route($name, $parameters()), $payload());

    expect($response->getStatusCode())->toBeIn([204, 302]);
})->with('reader writes');

test('an admin is turned away from the data export download', function () {
    $admin = User::factory()->admin()->create();
    $link = URL::temporarySignedRoute('exports.download', now()->addHour(), ['user' => $admin->id, 'file' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']);

    $this->actingAs($admin)->get($link)->assertRedirect(route('admin.index'));
});

test('an admin reaches every admin page and the account pages', function (string $name, Closure $parameters) {
    $this->actingAs(User::factory()->admin()->create());

    if ($name === 'security.edit') {
        $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    $this->get(route($name, $parameters()))->assertOk();
})->with('admin pages');

test('an admin still sees covers and the settings redirect', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/settings')->assertRedirect('settings/profile');
    expect($this->get(route('covers.show', Media::factory()->create()))->getStatusCode())->not->toBe(403);
});

test('an admin who is not verified is asked to verify before anything else', function () {
    $this->actingAs(User::factory()->admin()->unverified()->create())->get(route('home'))->assertRedirect(route('verification.notice'));
});

test('a guest is unaffected: reader pages go to sign in, the landing page shows', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
    $this->post(route('library.store'))->assertRedirect(route('login'));
    $this->get(route('landing'))->assertOk();
});

test('an admin cannot add to a library through the listener or any reader component', function () {
    $admin = User::factory()->admin()->create();
    $media = Media::factory()->create();

    Livewire::actingAs($admin)->test('library-listener')->dispatch('library-add', id: $media->id)->assertForbidden();
    Livewire::actingAs($admin)->test('library-controls', ['media' => $media])->call('add')->assertForbidden();

    expect(LibraryEntry::count())->toBe(0);
});

test('a reader can still add through the listener', function () {
    $media = Media::factory()->create();

    Livewire::actingAs(User::factory()->create())->test('library-listener')->dispatch('library-add', id: $media->id)->assertOk();

    expect(LibraryEntry::count())->toBe(1);
});

test('an admin keeps the admin components', function () {
    Livewire::actingAs(User::factory()->admin()->create())->test('pages::admin.users.index')->assertOk();
});

test('an admin has no reading profile on the profile page and cannot save one', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('profile.edit'))->assertOk()
        ->assertSee('Name and Email')
        ->assertDontSee('Reading Profile')
        ->assertDontSee('how you like to read');

    Livewire::actingAs($admin)->test('pages::settings.profile')
        ->set('bio', 'An admin who reads')
        ->call('updateReadingProfile')
        ->assertForbidden();

    expect($admin->profile()->value('bio'))->toBeNull();
});

test('a reader keeps the reading profile on the profile page', function () {
    $this->actingAs(User::factory()->create())->get(route('profile.edit'))->assertOk()
        ->assertSee('Name and Email')
        ->assertSee('Reading Profile');
});
