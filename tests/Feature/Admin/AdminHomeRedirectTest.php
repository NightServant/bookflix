<?php

use App\Models\User;
use Illuminate\Support\Facades\URL;

/*
 * Wherever a signed-in user is sent "home", an admin lands on the admin dashboard and a reader on Home (see User::homePath).
 */

test('signing in sends an admin to the admin dashboard and a reader to Home', function () {
    $admin = User::factory()->admin()->create();
    $reader = User::factory()->create();

    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('admin.index', absolute: false));

    auth()->logout();

    $this->post(route('login.store'), ['email' => $reader->email, 'password' => 'password'])->assertRedirect(route('home', absolute: false));
});

test('the landing page and the guest pages send a signed-in admin to the dashboard and a reader to Home', function () {
    $this->actingAs(User::factory()->admin()->create());
    $this->get(route('landing'))->assertRedirect(route('admin.index', absolute: false));
    $this->get(route('login'))->assertRedirect(route('admin.index', absolute: false));

    $this->actingAs(User::factory()->create());
    $this->get(route('landing'))->assertRedirect(route('home'));
    $this->get(route('login'))->assertRedirect(route('home'));
});

test('following the verification link sends an admin to the dashboard', function () {
    $admin = User::factory()->admin()->unverified()->create();
    $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $admin->id, 'hash' => sha1($admin->email)]);

    $this->actingAs($admin)->get($link)->assertRedirect(route('admin.index', absolute: false).'?verified=1');
});
