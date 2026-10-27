<?php

namespace App\Catalog\Signals;

use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Catalog\Providers\SendsProviderRequests;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\RateLimiter;

trait SendsSignalRequests
{
    use SendsProviderRequests;

    /**
     * Wait for a slot under the source's `rate_per_minute`, then send.
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

        while (RateLimiter::tooManyAttempts($key, $max)) {
            sleep(max(1, RateLimiter::availableIn($key)));
        }

        RateLimiter::hit($key, 60);

        return $this->send($request);
    }
}
