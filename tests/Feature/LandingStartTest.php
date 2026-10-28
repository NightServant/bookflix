<?php

use App\Models\User;

test('the sign-up box sends the typed email on to the registration form without putting it in the url', function () {
    $response = $this->post(route('landing.start'), ['email' => 'reader@example.com']);

    $response->assertRedirect(route('register'));
    expect($response->headers->get('Location'))->not->toContain('reader');

    $this->followingRedirects()->post(route('landing.start'), ['email' => 'reader@example.com'])
        ->assertSee('value="reader@example.com"', false);
});

test('a missing or malformed email goes back to the sign-up box with a message', function (?string $email) {
    $this->from(route('landing'))->post(route('landing.start'), ['email' => $email])
        ->assertRedirect(route('landing').'#start')
        ->assertSessionHasErrors('email');
})->with([null, '', 'not-an-email', 'two@@example.com']);

test('the sign-up box is throttled', function () {
    foreach (range(1, 20) as $i) {
        $this->post(route('landing.start'), ['email' => 'reader@example.com'])->assertRedirect();
    }

    $this->post(route('landing.start'), ['email' => 'reader@example.com'])->assertStatus(429);
});

test('a signed-in reader is still sent home from the landing page', function () {
    $this->actingAs(User::factory()->create())->get(route('landing'))->assertRedirect(route('home'));
});
