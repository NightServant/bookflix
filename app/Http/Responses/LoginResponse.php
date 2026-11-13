<?php

namespace App\Http\Responses;

use Laravel\Fortify\Http\Responses\LoginResponse as VendorLoginResponse;

/**
 * After sign-in an admin lands on the admin dashboard and a reader on Home (see User::homePath).
 */
class LoginResponse extends VendorLoginResponse
{
    public function toResponse($request)
    {
        return $request->wantsJson() ? parent::toResponse($request) : redirect()->intended($request->user()->homePath());
    }
}
