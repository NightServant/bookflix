<?php

use App\Models\User;

test('security headers are present', function () {
    $this->get(route('landing'))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
});

test('headers and request id also cover the deactivated-account redirect', function () {
    $response = $this->actingAs(User::factory()->deactivated()->create())->get(route('landing'));

    $response->assertRedirect(route('login'))->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Request-Id');
});
