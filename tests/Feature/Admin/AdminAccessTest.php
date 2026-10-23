<?php

use App\Models\User;

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
