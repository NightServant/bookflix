<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

test('sign-up is throttled per IP after five posts a minute', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post(route('register.store'), ['email' => "not-an-email-{$attempt}"])
            ->assertRedirect()->assertSessionHasErrors('email');
    }

    $this->post(route('register.store'), ['email' => 'not-an-email'])->assertTooManyRequests();
});

test('two wrong attempts never block a real person', function () {
    foreach (range(1, 2) as $attempt) {
        $this->post(route('register.store'), ['email' => "not-an-email-{$attempt}"])->assertSessionHasErrors('email');
    }

    $this->post(route('register.store'), [
        'name' => 'Real Person',
        'email' => 'real@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors()->assertRedirect(route('home', absolute: false));

    expect(User::query()->where('email', 'real@example.com')->exists())->toBeTrue();
});

test('reset links are throttled per email, but another email from the same IP still gets one', function () {
    // Unknown addresses, so the broker's own once-a-minute-per-account wait never answers instead of this limiter.
    foreach (range(1, 5) as $attempt) {
        $this->post(route('password.email'), ['email' => 'Reader@Example.com'])->assertRedirect()->assertSessionHasErrors('email');
    }

    $this->post(route('password.email'), ['email' => 'reader@example.com'])->assertTooManyRequests();
    $this->post(route('password.email'), ['email' => 'someone-else@example.com'])->assertRedirect()->assertStatus(302);
});

test('one IP cannot request reset links for more than twenty emails a minute', function () {
    foreach (range(1, 20) as $n) {
        $this->post(route('password.email'), ['email' => "reader{$n}@example.com"])->assertRedirect();
    }

    $this->post(route('password.email'), ['email' => 'reader21@example.com'])->assertTooManyRequests();
});

test('reset submissions are throttled per email', function () {
    $payload = ['token' => 'bogus', 'email' => 'reader@example.com', 'password' => 'password', 'password_confirmation' => 'password'];

    foreach (range(1, 5) as $attempt) {
        $this->post(route('password.update'), $payload)->assertRedirect()->assertSessionHasErrors('email');
    }

    $this->post(route('password.update'), $payload)->assertTooManyRequests();
    $this->post(route('password.update'), [...$payload, 'email' => 'other@example.com'])->assertRedirect();
});

test('password confirmation is throttled per signed-in user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'wrong'])->assertRedirect()->assertSessionHasErrors('password');
    }

    $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'wrong'])->assertTooManyRequests();
    $this->actingAs($other)->post(route('password.confirm.store'), ['password' => 'wrong'])->assertRedirect()->assertSessionHasErrors('password');
});

test('the sign-in, sign-up and reset pages themselves are never throttled', function () {
    $user = User::factory()->create();

    foreach (range(1, 12) as $ignored) {
        $this->get(route('register'))->assertOk();
        $this->get(route('password.request'))->assertOk();
        $this->get(route('login'))->assertOk();
        $this->get(route('password.reset', 'a-token'))->assertOk();
        $this->actingAs($user)->get(route('password.confirm'))->assertOk();
        auth()->logout();
    }
});

test('routes with a limiter of their own keep their existing limits', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong'])->assertRedirect()->assertSessionHasErrors('email');
    }
    $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong'])->assertTooManyRequests();

    $user = User::factory()->unverified()->create();
    foreach (range(1, 6) as $attempt) {
        $this->actingAs($user)->post(route('verification.send'))->assertRedirect();
    }
    $this->actingAs($user)->post(route('verification.send'))->assertTooManyRequests();
});

test('throttle counters do not leak between tests', function () {
    expect(RateLimiter::attempts('register|127.0.0.1'))->toBe(0);
});
