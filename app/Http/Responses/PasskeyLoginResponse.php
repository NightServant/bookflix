<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Passkeys\Http\Responses\PasskeyLoginResponse as VendorPasskeyLoginResponse;

/**
 * After a passkey sign-in: same landing as LoginResponse. The page asks for JSON, so the target is named in the body.
 */
class PasskeyLoginResponse extends VendorPasskeyLoginResponse
{
    public function toResponse($request)
    {
        $redirect = redirect()->intended($request->user()->homePath());

        return $request->wantsJson() ? new JsonResponse(['redirect' => $redirect->getTargetUrl()], 200) : $redirect;
    }
}
