<?php

namespace App\Http\Middleware;

use App\Livewire\Actions\Logout;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function __construct(private Logout $logout) {}

    /**
     * Log out a deactivated user, whichever way they authenticated (password, passkey, remember-me).
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isDeactivated()) {
            ($this->logout)();

            return redirect()->route('login');
        }

        return $next($request);
    }
}
