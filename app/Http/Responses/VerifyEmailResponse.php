<?php

namespace App\Http\Responses;

use Laravel\Fortify\Http\Responses\VerifyEmailResponse as VendorVerifyEmailResponse;

/**
 * After the email link is followed: an admin to the admin dashboard, a reader to Home, with the verified flag.
 */
class VerifyEmailResponse extends VendorVerifyEmailResponse
{
    public function toResponse($request)
    {
        return $request->wantsJson() ? parent::toResponse($request) : redirect()->intended($request->user()->homePath().'?verified=1');
    }
}
