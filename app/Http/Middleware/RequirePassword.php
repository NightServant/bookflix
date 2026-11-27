<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword as BaseRequirePassword;

/**
 * The framework's password.confirm, except that a JSON client with a lapsed confirmation is stopped with an exception.
 * The framework answers it with a plain 423 response, which Livewire's persistent-middleware replay discards (it only stops on a throw or a
 * redirect), so a replayed security-page update from a JSON client would run unconfirmed. A thrown 423 stops it there and answers the same 423 elsewhere.
 */
class RequirePassword extends BaseRequirePassword
{
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null)
    {
        if ($request->expectsJson() && $this->shouldConfirmPassword($request, $passwordTimeoutSeconds === null ? null : (int) $passwordTimeoutSeconds)) {
            abort(423, 'Password confirmation required.');
        }

        return parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
    }
}
