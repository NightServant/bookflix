<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureOnboarded;
use App\Http\Middleware\EnsureReaderAccount;
use App\Http\Middleware\RequirePassword;
use App\Http\Middleware\SetContentSecurityPolicy;
use App\Http\Middleware\SetSecurityHeaders;
use App\Support\SecurityLog;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AssignRequestId::class);
        $middleware->append(SetSecurityHeaders::class);
        $middleware->appendToGroup('web', EnsureAccountIsActive::class);
        $middleware->appendToGroup('web', SetContentSecurityPolicy::class);
        $middleware->alias(['onboarded' => EnsureOnboarded::class, 'reader' => EnsureReaderAccount::class, 'password.confirm' => RequirePassword::class]);
        $middleware->redirectUsersTo(fn (Request $request) => $request->user()?->homePath());
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Log 429s and 403s to the security log, then return null so the normal rendering carries on. Render callbacks run after the framework has turned an
        // AuthorizationException into a 403 HttpException, so `Gate::authorize`, `can:` and `abort(403)` (Livewire updates included) all arrive here.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request): ?Response {
            match ($e->getStatusCode()) {
                429 => SecurityLog::tooManyRequests($request),
                403 => SecurityLog::forbidden($request),
                default => null,
            };

            return null;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
