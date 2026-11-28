<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

/*
 * Behind a trusted proxy (Vercel), the client's address and the https flag come from the forwarded headers; with no proxy trusted they are ignored, so a
 * client that reaches the app directly cannot fake its IP or its scheme. The proxy list is `TRUSTED_PROXIES` (config/trustedproxy.php).
 */

beforeEach(function () {
    Route::middleware('web')->get('/_proxy-probe', fn (Request $request) => response()->json(['ip' => $request->ip(), 'secure' => $request->isSecure(), 'host' => $request->getHost()]));
});

// Symfony keeps the trusted proxies in a static, so each test starts and ends with none.
$forgetProxies = fn () => Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
beforeEach($forgetProxies);
afterEach($forgetProxies);

/** One request from the proxy at 10.0.0.1 carrying what a proxy adds for a client at 203.0.113.7. */
function viaProxy($test, string $path = '/_proxy-probe', string $client = '203.0.113.7')
{
    return $test->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeaders(['X-Forwarded-For' => $client, 'X-Forwarded-Proto' => 'https'])
        ->get($path);
}

test('with no proxy trusted the forwarded headers are ignored', function () {
    config(['trustedproxy.proxies' => null]);

    viaProxy($this)->assertOk()->assertJson(['ip' => '10.0.0.1', 'secure' => false])->assertHeaderMissing('Strict-Transport-Security');
});

test('with every proxy trusted the forwarded client address and scheme are used, and HSTS is sent', function (string|array $proxies) {
    config(['trustedproxy.proxies' => $proxies]);

    viaProxy($this)->assertOk()->assertJson(['ip' => '203.0.113.7', 'secure' => true])->assertHeader('Strict-Transport-Security');
})->with([
    'every address' => ['*'],
    'a list holding the proxy' => [['192.0.2.1', '10.0.0.1']],
    'the same list as the env string' => ['192.0.2.1, 10.0.0.1'],
]);

test('a list that does not hold the calling proxy is ignored', function () {
    config(['trustedproxy.proxies' => ['192.0.2.1']]);

    viaProxy($this)->assertOk()->assertJson(['ip' => '10.0.0.1', 'secure' => false])->assertHeaderMissing('Strict-Transport-Security');
});

test('the per-IP throttles count the forwarded client address when proxies are trusted', function () {
    config(['trustedproxy.proxies' => '*']);

    $signUp = fn (string $client) => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeaders(['X-Forwarded-For' => $client])
        ->post(route('register.store'), ['email' => 'not-an-email']);

    foreach (range(1, 5) as $ignored) {
        $signUp('203.0.113.7')->assertRedirect();
    }

    // The same client is throttled; a different client behind the same proxy is not.
    $signUp('203.0.113.7')->assertTooManyRequests();
    $signUp('198.51.100.9')->assertRedirect();
});

// X-Forwarded-Host is deliberately not trusted: the reset and verification mails build their links from the request's host, so a client-supplied one would
// put an attacker's address in a real Bookflix email that carries the victim's token.
test('with every proxy trusted, X-Forwarded-Host still does not change the host', function () {
    config(['trustedproxy.proxies' => '*']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeaders(['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-For' => '203.0.113.7'])
        ->get('/_proxy-probe')->assertOk()->assertJson(['host' => 'localhost', 'ip' => '203.0.113.7']);
});

test('a password reset mail sent through a trusted proxy never links to a client-supplied host', function () {
    config(['trustedproxy.proxies' => '*']);
    Notification::fake();
    $user = User::factory()->create();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeaders(['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'https'])
        ->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $link = $notification->toMail($user)->actionUrl;

        return parse_url($link, PHP_URL_HOST) === 'localhost' && ! str_contains($link, 'evil.example');
    });
});

test('a verification mail sent through a trusted proxy never links to a client-supplied host', function () {
    config(['trustedproxy.proxies' => '*']);
    Notification::fake();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeaders(['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'https'])
        ->post(route('register.store'), ['name' => 'New Reader', 'email' => 'new@example.com', 'password' => 'password', 'password_confirmation' => 'password'])
        ->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'new@example.com')->firstOrFail();

    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user) {
        $link = $notification->toMail($user)->actionUrl;

        return parse_url($link, PHP_URL_HOST) === 'localhost' && ! str_contains($link, 'evil.example');
    });
});

test('TRUSTED_PROXIES is read as unset, a star, or a trimmed comma list', function (mixed $env, mixed $expected) {
    $keep = [$_ENV['TRUSTED_PROXIES'] ?? null, $_SERVER['TRUSTED_PROXIES'] ?? null];

    if ($env === null) {
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
    } else {
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $env;
    }

    try {
        expect((require config_path('trustedproxy.php'))['proxies'])->toBe($expected);
    } finally {
        [$_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']] = $keep;

        if ($keep[0] === null) {
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        }
    }
})->with([
    'unset' => [null, null],
    'empty' => ['', null],
    'blank' => ['  ', null],
    'a star' => ['*', '*'],
    'a list' => ['10.0.0.1, 192.0.2.0/24 ,  2001:db8::1', ['10.0.0.1', '192.0.2.0/24', '2001:db8::1']],
    'a single address' => ['10.0.0.1', ['10.0.0.1']],
]);
