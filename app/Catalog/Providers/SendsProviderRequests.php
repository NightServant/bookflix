<?php

namespace App\Catalog\Providers;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

trait SendsProviderRequests
{
    protected function client(): PendingRequest
    {
        return Http::withUserAgent((string) config('bookflix.provider_user_agent'))
            ->acceptJson()
            ->timeout(15)
            ->retry(0);
    }

    /**
     * Run a request, turning provider throttling and outages into typed exceptions. A 404 is returned for the caller to handle.
     *
     * @param  Closure(): Response  $request
     *
     * @throws RateLimitedByProvider
     * @throws ProviderUnavailable
     */
    protected function send(Closure $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            throw new ProviderUnavailable($exception->getMessage(), previous: $exception);
        }

        if ($response->status() === 429) {
            throw new RateLimitedByProvider((int) $response->header('Retry-After') ?: 60);
        }

        if ($response->failed() && $response->status() !== 404) {
            throw new ProviderUnavailable("Provider answered HTTP {$response->status()}.");
        }

        return $response;
    }
}
