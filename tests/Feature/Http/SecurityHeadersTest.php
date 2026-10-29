<?php

use App\Models\User;

test('security headers are present', function () {
    $this->get(route('landing'))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
});

test('no page sends its address to another site, so hotlink-protected covers (MangaDex) are served, not their placeholder', function () {
    $this->get(route('landing'))->assertHeader('Referrer-Policy', 'same-origin');
});

test('headers and request id also cover the deactivated-account redirect', function () {
    $response = $this->actingAs(User::factory()->deactivated()->create())->get(route('landing'));

    $response->assertRedirect(route('login'))->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Request-Id');
});

test('headers and request id cover guest redirects and 404s', function () {
    $this->get(route('home'))
        ->assertRedirect(route('login'))
        ->assertHeader('X-Request-Id')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertHeader('X-Request-Id')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});
