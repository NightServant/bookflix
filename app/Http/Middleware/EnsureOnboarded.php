<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a signed-in reader who has not been through onboarding (or skipped it) to it, for a page view. Only Home is behind it, so the rest of
 * the app stays open to someone who skipped nothing yet, and a reader is never trapped: onboarding itself, settings, logout, admin and the
 * email verification screens are exempt even if a route group ever puts this on them. A form post is never turned into a redirect.
 * Readers who existed before onboarding were marked onboarded by its migration (D-37); a new reader has no profile row, which reads as not onboarded.
 */
class EnsureOnboarded
{
    /** Route names that are always reachable. */
    private const EXEMPT = ['onboarding', 'profile.edit', 'privacy.edit', 'security.edit', 'preferences.edit', 'logout', 'admin.*', 'verification.*', 'password.*', 'two-factor.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $request->isMethodSafe() && $user->profile->onboarded_at === null && ! $request->routeIs(...self::EXEMPT)) {
            return redirect()->route('onboarding');
        }

        return $next($request);
    }
}
