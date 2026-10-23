<?php

use App\Models\Media;
use App\Models\User;
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
