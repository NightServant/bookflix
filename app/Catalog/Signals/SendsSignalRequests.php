<?php

namespace App\Catalog\Signals;

use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Catalog\Providers\SendsProviderRequests;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

trait SendsSignalRequests
{
    use SendsProviderRequests;

    private const MAX_ATTEMPTS = 3;

    /**
     * Wait for a slot under the source's `rate_per_minute`, then send; a provider 429 is waited out and retried before giving up.
     *
     * @param  Closure(): Response  $request
     *
     * @throws RateLimitedByProvider
     * @throws ProviderUnavailable
     */
    protected function throttled(string $source, Closure $request): Response
    {
        $key = "signals:{$source}";
        $max = max(1, (int) config("bookflix.providers.{$source}.rate_per_minute"));

        for ($attempt = 1; ; $attempt++) {
            while (RateLimiter::tooManyAttempts($key, $max)) {
                Sleep::for(max(1, RateLimiter::availableIn($key)))->seconds();
            }

            RateLimiter::hit($key, 60);

            try {
                return $this->send($request);
            } catch (RateLimitedByProvider $exception) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $exception;
                }

                Sleep::for(min($exception->retryAfter, 60))->seconds();
            }
        }
    }
}
