<?php

namespace App\Ml;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class MlClient
{
    /**
     * Whether the ML service answers its health check with a 2xx.
     */
    public function health(): bool
    {
        try {
            return $this->request()->get('/health')->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * @return array{embedding: array{name: string, dims: int}}
     *
     * @throws MlServiceUnavailable
     */
    public function models(): array
    {
        try {
            /** @var array{embedding: array{name: string, dims: int}} */
            return $this->request()->get('/v1/models')->throw()->json();
        } catch (HttpClientException $exception) {
            throw new MlServiceUnavailable('The ML service is unavailable.', previous: $exception);
        }
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl((string) config('services.ml.url'))
            ->withToken((string) config('services.ml.token'))
            ->timeout((int) config('services.ml.timeout'))
            ->acceptJson();
    }
}
