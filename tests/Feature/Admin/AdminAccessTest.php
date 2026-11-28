<?php

use App\Models\Collection as StoryCollection;
use App\Models\DuplicateCandidate;
use App\Models\Media;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

test('guests are redirected away from admin', function () {
    $this->get(route('admin.index'))->assertRedirect(route('login'));
});

test('non admins are forbidden from admin', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.index'))->assertForbidden();
});

test('admins can open admin', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.index'))->assertOk();
});

test('is_admin cannot be mass assigned', function () {
    expect((new User)->isFillable('is_admin'))->toBeFalse();
});

test('a demoted admin is refused on the next livewire request', function (string $component) {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $page = Livewire::test($component, $component === 'pages::admin.media.edit' ? ['media' => Media::factory()->create()] : []);

    $admin->forceFill(['is_admin' => false])->save();

    $page->call('$refresh')->assertForbidden();
})->with(['pages::admin.providers.index', 'pages::admin.media.index', 'pages::admin.media.edit', 'pages::admin.duplicates.index']);

test('a demoted admin cannot run admin actions', function (string $component, string $method, Closure $params) {
    Http::fake(['*/health' => Http::response(['status' => 'ok'])]);
    $admin = User::factory()->admin()->create();
    $page = Livewire::actingAs($admin)->test($component);
    $arguments = $params();
    $admin->forceFill(['is_admin' => false])->save();

    $page->call($method, ...$arguments)->assertForbidden();
})->with([
    'hide' => ['pages::admin.moderation.index', 'hide', fn () => [Report::factory()->of(Review::factory()->create())->create()->id]],
    'dismiss' => ['pages::admin.moderation.index', 'dismiss', fn () => [Report::factory()->of(Review::factory()->create())->create()->id]],
    'restore' => ['pages::admin.moderation.index', 'restore', fn () => [Report::factory()->of(Review::factory()->create())->create()->id]],
    'run now' => ['pages::admin.models.index', 'runNow', fn () => ['popularity']],
    'create a pick' => ['pages::admin.staff-picks.index', 'create', fn () => []],
    'edit a pick' => ['pages::admin.staff-picks.index', 'edit', fn () => [StoryCollection::factory()->editorial()->create()->id]],
    'add a story' => ['pages::admin.staff-picks.index', 'addStory', fn () => [Media::factory()->create()->id]],
    'remove a story' => ['pages::admin.staff-picks.index', 'removeStory', fn () => [Media::factory()->create()->id]],
    'move a story' => ['pages::admin.staff-picks.index', 'moveStory', fn () => [0, 1]],
    'save a pick' => ['pages::admin.staff-picks.index', 'save', fn () => []],
]);

test('admin id properties cannot be set from the browser', function (string $component, string $property) {
    expect(fn () => Livewire::actingAs(User::factory()->admin()->create())->test($component)->set($property, 1))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    ['pages::admin.classifications.index', 'rowId'],
    ['pages::admin.classifications.index', 'mediaId'],
    ['pages::admin.content.index', 'verifying'],
    ['pages::admin.duplicates.index', 'comparing'],
    ['pages::admin.staff-picks.index', 'storyIds'],
]);

test('the admin dashboard shows live counts within a small query budget and caches them', function () {
    Cache::forget('admin.dashboard-counts');
    DuplicateCandidate::factory()->count(2)->create();
    $admin = User::factory()->admin()->create();

    DB::enableQueryLog();
    $this->actingAs($admin)->get(route('admin.index'))->assertOk()->assertSee('2 pairs waiting')->assertSee('Never synced');
    $cold = count(DB::getQueryLog());

    DB::flushQueryLog();
    $this->actingAs($admin)->get(route('admin.index'))->assertOk();
    $warm = count(DB::getQueryLog());

    expect($cold)->toBeLessThanOrEqual(12)->and($warm)->toBeLessThan($cold);
});

test('the dashboard stat pills group thousands', function () {
    Cache::put('admin.dashboard-counts', ['media' => 12345, 'users' => 3, 'duplicates' => 1200, 'classifications' => 1305, 'sources' => 1, 'lastRun' => null], 60);

    $this->actingAs(User::factory()->admin()->create())->get(route('admin.index'))->assertOk()
        ->assertSee('1,305 to review')->assertSee('1,200 pairs waiting')->assertSee('12,345 stories');
});
