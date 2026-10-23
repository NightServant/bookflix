<?php

namespace App\Ml;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Context;
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
        } catch (ConnectionException|MlServiceUnavailable) {
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

    /**
     * @throws MlServiceUnavailable
     */
    private function request(): PendingRequest
    {
        $token = (string) config('services.ml.token');

        if ($token === '') {
            throw new MlServiceUnavailable('The ML service token is not configured.');
        }

        return Http::baseUrl((string) config('services.ml.url'))
            ->withToken($token)
            ->timeout((int) config('services.ml.timeout'))
            ->acceptJson()
            ->when(
                Context::get('request_id'),
                fn (PendingRequest $request, string $id) => $request->withHeaders(['X-Request-Id' => $id]),
            );
    }
}
