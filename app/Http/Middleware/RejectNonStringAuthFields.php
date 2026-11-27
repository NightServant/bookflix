<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Part of Fortify's route middleware (config/fortify.php). Fortify's own controllers lower-case, hash and compare these fields as strings, so a list
 * posted for one (`email[]=x`) crashed them with a 500. Here a list, or any other non-string value, becomes the normal "must be a string" validation
 * error before a controller runs. Only the text fields of the auth forms are checked, and only on a POST; a missing field is left to the form's own rules.
 */
class RejectNonStringAuthFields
{
    /** The text fields the Fortify forms post (sign-up, reset, sign-in, password confirmation, two-factor challenge). */
    private const FIELDS = ['name', 'email', 'password', 'password_confirmation', 'current_password', 'token', 'code', 'recovery_code'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST')) {
            $messages = [];

            foreach (self::FIELDS as $field) {
                $value = $request->input($field);

                if ($value !== null && ! is_string($value)) {
                    $messages[$field] = __('validation.string', ['attribute' => __(str_replace('_', ' ', $field))]);
                }
            }

            if ($messages !== []) {
                throw ValidationException::withMessages($messages);
            }
        }

        return $next($request);
    }
}
