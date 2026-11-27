<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

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

test('secure responses tell browsers to stay on https', function () {
    $this->get('https://localhost/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('secure error and redirect responses carry it too', function () {
    $this->get('https://localhost/no-such-page')->assertNotFound()->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    $this->get('https://localhost/home')->assertRedirect()->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('plain http responses carry no HSTS header', function () {
    $this->get('http://localhost/up')->assertHeaderMissing('Strict-Transport-Security');
    $this->get('http://localhost/no-such-page')->assertHeaderMissing('Strict-Transport-Security');
});

test('the private disk is never served over http', function () {
    expect(Route::has('storage.local'))->toBeFalse();
});

test('an uploaded SVG never gets a preview url', function () {
    expect(config('livewire.temporary_file_upload.preview_mimes'))->not->toContain('svg');
});
