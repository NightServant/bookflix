<?php

namespace App\Http\Responses;

use Laravel\Fortify\Http\Responses\TwoFactorLoginResponse as VendorTwoFactorLoginResponse;

/**
 * After the two-factor challenge: same landing as LoginResponse.
 */
class TwoFactorLoginResponse extends VendorTwoFactorLoginResponse
{
    public function toResponse($request)
    {
        return $request->wantsJson() ? parent::toResponse($request) : redirect()->intended($request->user()->homePath());
    }
}
