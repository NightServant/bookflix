<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The security log (`security` channel, storage/logs/security.log, daily): one structured line per failed sign-in, failed two-factor code, 429 and 403 (and lockout where Fortify fires one), so a
 * guessing run or a probing client shows up in one place. A line holds the event, the route name, the method, the IP, the status for an HTTP
 * refusal and the user id when the visitor is signed in or the account is known. For a failed sign-in it holds a short SHA-256 prefix of the
 * lower-cased email, never the email itself. It never holds a password, token, code, header, query string or request body: nothing from the
 * request is passed through except what is named here.
 */
class SecurityLog
{
    /** A sign-in that failed. `$email` is hashed here; `$userId` is set when the email belongs to an account. */
    public static function failedSignIn(Request $request, mixed $email, int|string|null $userId = null): void
    {
        self::write('failed_sign_in', $request, ['email_hash' => self::emailHash($email), 'user_id' => $userId]);
    }

    /**
     * A sign-in refused because there were too many attempts (the framework's Lockout event). With the `login` limiter configured in Fortify (as here)
     * Fortify never fires Lockout, so a throttled sign-in is logged as `too_many_requests` on `login.store` instead; this stays for a configuration that does.
     */
    public static function lockout(Request $request, mixed $email): void
    {
        self::write('lockout', $request, ['email_hash' => self::emailHash($email)]);
    }

    /** A wrong two-factor code or recovery code at the challenge (Fortify's TwoFactorAuthenticationFailed). Never the code itself. */
    public static function failedTwoFactor(Request $request, int|string|null $userId): void
    {
        self::write('failed_two_factor', $request, ['user_id' => $userId]);
    }

    /** A request answered 429. */
    public static function tooManyRequests(Request $request): void
    {
        self::write('too_many_requests', $request, ['status' => 429, 'user_id' => $request->user()?->getAuthIdentifier()]);
    }

    /** A request answered 403 (a page, an action or a Livewire update the visitor may not use). */
    public static function forbidden(Request $request): void
    {
        self::write('forbidden', $request, ['status' => 403, 'user_id' => $request->user()?->getAuthIdentifier()]);
    }

    /** The first 12 hex characters of the SHA-256 of the lower-cased email: enough to tell attempts on one address apart, not to read it. */
    private static function emailHash(mixed $email): string
    {
        return substr(hash('sha256', is_string($email) ? mb_strtolower(trim($email)) : ''), 0, 12);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private static function write(string $event, Request $request, array $extra = []): void
    {
        // A log that cannot be written (a file another user owns, a full disk) must never turn a 403, a 429 or a wrong password into a 500: this runs inside
        // the exception handler and the auth listeners. The failure is reported on the default channel, guarded so that cannot throw either.
        try {
            Log::channel('security')->warning('security.'.$event, array_filter([
                'event' => $event,
                'route' => $request->route()?->getName(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                ...$extra,
            ], fn (mixed $value): bool => $value !== null));
        } catch (Throwable $failure) {
            try {
                report($failure);
            } catch (Throwable) {
                // Nothing left to do.
            }
        }
    }
}
