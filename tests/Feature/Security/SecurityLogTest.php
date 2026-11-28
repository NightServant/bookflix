<?php

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;

/** A log handler that cannot write, like a file owned by another user. */
class ThrowingSecurityHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        throw new RuntimeException('The security log cannot be written.');
    }
}

/*
 * The security log: one structured line per failed sign-in, lockout, 429 and 403, with the event, route name, method, IP and the user id when known.
 * A failed sign-in carries a short hash of the lower-cased email, never the email; no line ever holds a password, token, code or request body.
 */

beforeEach(function () {
    config(['logging.channels.security' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'info']]);
    Log::forgetChannel('security');
});

/** @return list<LogRecord> */
function securityRecords(?string $event = null): array
{
    /** @var TestHandler $handler */
    $handler = Log::channel('security')->getLogger()->getHandlers()[0];

    return array_values(array_filter($handler->getRecords(), fn (LogRecord $record) => $event === null || $record->message === $event));
}

/** The whole log as one string, to prove what must never be in it is not. */
function securityLogText(): string
{
    return implode("\n", array_map(fn (LogRecord $record) => $record->message.' '.json_encode($record->context), securityRecords()));
}

test('a failed sign-in logs the event, route, method, IP and a short hash of the email, never the email or password', function () {
    $this->post(route('login.store'), ['email' => 'Someone@Example.com', 'password' => 'the-wrong-secret'])->assertSessionHasErrors();

    $records = securityRecords('security.failed_sign_in');

    expect($records)->toHaveCount(1)
        ->and($records[0]->context)->toMatchArray([
            'event' => 'failed_sign_in',
            'route' => 'login.store',
            'method' => 'POST',
            'ip' => '127.0.0.1',
            'email_hash' => substr(hash('sha256', 'someone@example.com'), 0, 12),
        ])
        ->and($records[0]->context)->not->toHaveKey('user_id');

    expect(securityLogText())->not->toContain('Someone')->not->toContain('someone@')->not->toContain('the-wrong-secret');
});

test('a failed sign-in for a real account also names the user id', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password-here']);

    expect(securityRecords('security.failed_sign_in')[0]->context)->toMatchArray(['user_id' => $user->id]);
    expect(securityLogText())->not->toContain($user->email)->not->toContain('wrong-password-here');
});

test('a lockout logs one line', function () {
    $request = Request::create('/login', 'POST', ['email' => 'locked@example.com', 'password' => 'x']);
    $request->setRouteResolver(fn () => (new Route('POST', 'login', []))->name('login.store'));

    event(new Lockout($request));

    $records = securityRecords('security.lockout');

    expect($records)->toHaveCount(1)->and($records[0]->context)->toMatchArray([
        'event' => 'lockout',
        'route' => 'login.store',
        'method' => 'POST',
        'email_hash' => substr(hash('sha256', 'locked@example.com'), 0, 12),
    ]);
    expect(securityLogText())->not->toContain('locked@example.com');
});

test('a throttled request logs a 429 with its route', function () {
    foreach (range(1, 5) as $ignored) {
        $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'secret-guess']);
    }

    $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'secret-guess'])->assertTooManyRequests();

    $records = securityRecords('security.too_many_requests');

    expect($records)->toHaveCount(1)->and($records[0]->context)->toMatchArray(['event' => 'too_many_requests', 'route' => 'login.store', 'method' => 'POST', 'status' => 429, 'ip' => '127.0.0.1']);
    expect(securityLogText())->not->toContain('secret-guess')->not->toContain('nobody@example.com');
});

test('a forbidden page logs a 403 with the user id', function () {
    $reader = User::factory()->create();

    $this->actingAs($reader)->get(route('admin.index'))->assertForbidden();

    $records = securityRecords('security.forbidden');

    expect($records)->toHaveCount(1)->and($records[0]->context)->toMatchArray(['event' => 'forbidden', 'route' => 'admin.index', 'method' => 'GET', 'status' => 403, 'user_id' => $reader->id]);
});

test('a Livewire update refused with 403 is logged too, without its payload', function () {
    $html = $this->actingAs(User::factory()->admin()->create())->get(route('admin.providers'))->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
    $snapshot = collect($matches[1])->map(fn ($encoded) => html_entity_decode($encoded, ENT_QUOTES))->first(fn ($raw) => str_contains($raw, 'pages::admin.providers.index'));

    $payload = ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['method' => 'syncNow', 'params' => ['secret-param'], 'metadata' => []]]]]];

    $this->actingAs(User::factory()->create())->call('POST', Livewire::getUpdateUri(), server: $this->transformHeadersToServerVars(['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Livewire' => '1']), content: json_encode($payload))
        ->assertForbidden();

    $records = securityRecords('security.forbidden');

    expect($records)->toHaveCount(1)->and($records[0]->context)->toMatchArray(['event' => 'forbidden', 'method' => 'POST', 'status' => 403]);
    expect(securityLogText())->not->toContain('secret-param')->not->toContain('snapshot');
});

test('pages that answer normally write nothing', function () {
    $this->get(route('landing'))->assertOk();
    $this->get('/no-such-page')->assertNotFound();

    expect(securityRecords())->toBe([]);
});

test('a failed two-factor code logs the user id and never the code', function () {
    $user = User::factory()->withTwoFactor()->create(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP')]);

    $this->withSession(['login.id' => $user->id])->post(route('two-factor.login.store'), ['code' => '987654'])->assertSessionHasErrors();

    $records = securityRecords('security.failed_two_factor');

    expect($records)->toHaveCount(1)->and($records[0]->context)->toMatchArray(['event' => 'failed_two_factor', 'route' => 'two-factor.login.store', 'method' => 'POST', 'user_id' => $user->id]);
    expect(securityLogText())->not->toContain('987654');
});

test('a security log that cannot be written never turns a response into a 500', function () {
    config(['logging.channels.security' => ['driver' => 'monolog', 'handler' => ThrowingSecurityHandler::class, 'level' => 'info']]);
    Log::forgetChannel('security');

    // A 403 page, a 429 and a wrong password all still answer as they would with a working log.
    $this->actingAs(User::factory()->create())->get(route('admin.index'))->assertForbidden();
    auth()->forgetGuards();

    foreach (range(1, 5) as $ignored) {
        $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong-guess'])->assertSessionHasErrors('email');
    }

    $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong-guess'])->assertTooManyRequests();
});
