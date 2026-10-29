<?php

use App\Models\DuplicateCandidate;
use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
