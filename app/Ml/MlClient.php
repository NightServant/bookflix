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
     * Ask the service to start a job; it answers 202 with the id of the run.
     *
     * @param  array<string, mixed>  $params
     * @return string The service's run id.
     *
     * @throws MlServiceUnavailable
     */
    public function startJob(string $name, array $params = []): string
    {
        try {
            $response = $this->request()->post('/v1/jobs/'.rawurlencode($name), ['params' => (object) $params])->throw();
        } catch (HttpClientException $exception) {
            throw new MlServiceUnavailable('The ML service did not start the job.', previous: $exception);
        }

        $runId = $response->json('run_id');

        if ($response->status() !== 202 || ! is_string($runId) || $runId === '') {
            throw new MlServiceUnavailable('The ML service did not accept the job.');
        }

        return $runId;
    }

    /**
     * The state of a job run.
     *
     * @return array{status: string, started_at: ?string, finished_at: ?string, metrics: ?array<string, mixed>, error: ?string}
     *
     * @throws MlServiceUnavailable
     */
    public function job(string $runId): array
    {
        try {
            /** @var array{status: string, started_at: ?string, finished_at: ?string, metrics: ?array<string, mixed>, error: ?string} */
            return $this->request()->get('/v1/jobs/'.rawurlencode($runId))->throw()->json();
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
