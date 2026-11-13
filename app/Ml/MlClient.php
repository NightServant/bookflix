<?php

namespace App\Ml;

use App\Classification\ClassificationCandidate;
use App\Enums\ClassificationMethod;
use App\Models\Media;
use App\Models\ModelVersion;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
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
     * @return array{embedding: array{name: string, revision: string, dims: int}}
     *
     * @throws MlServiceUnavailable
     */
    public function models(): array
    {
        try {
            /** @var array{embedding: array{name: string, revision: string, dims: int}} */
            return $this->request()->get('/v1/models')->throw()->json();
        } catch (HttpClientException $exception) {
            throw new MlServiceUnavailable('The ML service is unavailable.', previous: $exception);
        }
    }

    /**
     * Embed texts (`kind` is `query` or `passage`). `$timeout` is in seconds and may be a fraction (search passes 0.3); without it the configured
     * timeout applies. Any failure, including an answer that is not one vector of `dims` numbers per text, is `MlServiceUnavailable`.
     *
     * @param  list<string>  $texts
     *
     * @throws MlServiceUnavailable
     */
    public function embed(array $texts, string $kind, ?float $timeout = null): EmbeddingResult
    {
        try {
            $response = $this->request($timeout)->post('/v1/embed', ['texts' => $texts, 'kind' => $kind])->throw();
        } catch (HttpClientException $exception) {
            throw new MlServiceUnavailable('The ML service did not embed the texts.', previous: $exception);
        }

        $modelVersion = $response->json('model_version');
        $dims = $response->json('dims');
        $vectors = $response->json('vectors');

        if (! is_string($modelVersion) || $modelVersion === '' || ! is_int($dims) || $dims < 1
            || ! is_array($vectors) || ! array_is_list($vectors) || count($vectors) !== count($texts)) {
            throw new MlServiceUnavailable('The ML service answered the embedding request out of shape.');
        }

        $floats = [];

        foreach ($vectors as $vector) {
            if (! is_array($vector) || ! array_is_list($vector) || count($vector) !== $dims) {
                throw new MlServiceUnavailable('The ML service answered the embedding request out of shape.');
            }

            foreach ($vector as $value) {
                if (! is_float($value) && ! is_int($value)) {
                    throw new MlServiceUnavailable('The ML service answered the embedding request out of shape.');
                }
            }

            $floats[] = array_map(floatval(...), $vector);
        }

        return new EmbeddingResult($modelVersion, $dims, $floats);
    }

    /**
     * The Dewey classifier's candidates for a work, from its title and description, genre, theme and tag names and a provider's own code,
     * strongest first, with their raw confidence (the caller caps it). Only answers of the active `dewey-classifier` version are taken. Any
     * failure, a 503 (no model, no classifier, or one trained on other vectors) or an answer out of shape is `MlServiceUnavailable`.
     * Call it from a queued job or a command only: it waits for the service.
     *
     * @return list<ClassificationCandidate>
     *
     * @throws MlServiceUnavailable
     */
    public function classifyDewey(Media $media): array
    {
        $media->loadMissing(['genres', 'themes', 'tags']);

        try {
            $response = $this->request()->post('/v1/classify/dewey', [
                'media_id' => $media->id,
                'title' => $media->title,
                'text' => (string) $media->description,
                'genres' => $media->genres->pluck('name')->values()->all(),
                'themes' => $media->themes->pluck('name')->values()->all(),
                'subjects' => $media->tags->pluck('name')->values()->all(),
                'external_code' => $media->externalIds()->whereNotNull('dewey_code')->orderBy('id')->value('dewey_code'),
            ])->throw();
        } catch (HttpClientException $exception) {
            throw new MlServiceUnavailable('The ML service did not classify the work.', previous: $exception);
        }

        $version = $response->json('model_version');
        $candidates = $response->json('candidates');
        $active = ModelVersion::activeFor('dewey-classifier');

        if (! is_string($version) || $active === null || $active->version !== $version || ! is_array($candidates) || ! array_is_list($candidates)) {
            throw new MlServiceUnavailable('The ML service answered the classification out of shape or from another version.');
        }

        return array_map(function (mixed $candidate) use ($active): ClassificationCandidate {
            $code = is_array($candidate) ? ($candidate['code'] ?? null) : null;
            $confidence = is_array($candidate) ? ($candidate['confidence'] ?? null) : null;

            if (! is_string($code) || $code === '' || ! (is_float($confidence) || is_int($confidence)) || $confidence < 0 || $confidence > 1) {
                throw new MlServiceUnavailable('The ML service answered the classification out of shape.');
            }

            return new ClassificationCandidate($code, (float) $confidence, ClassificationMethod::Automatic, 'dewey-classifier', $active->id);
        }, $candidates);
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
     * @throws MlRunNotFound when the service does not know the run
     * @throws MlServiceUnavailable
     */
    public function job(string $runId): array
    {
        try {
            /** @var array{status: string, started_at: ?string, finished_at: ?string, metrics: ?array<string, mixed>, error: ?string} */
            return $this->request()->get('/v1/jobs/'.rawurlencode($runId))->throw()->json();
        } catch (RequestException $exception) {
            throw $exception->response->status() === 404
                ? new MlRunNotFound('The ML service does not know this run.', previous: $exception)
                : new MlServiceUnavailable('The ML service is unavailable.', previous: $exception);
        } catch (HttpClientException $exception) {
            throw new MlServiceUnavailable('The ML service is unavailable.', previous: $exception);
        }
    }

    /**
     * The connect timeout is short and never longer than the whole request's, so a service that is down fails fast.
     *
     * @throws MlServiceUnavailable
     */
    private function request(?float $timeout = null): PendingRequest
    {
        $token = (string) config('services.ml.token');

        if ($token === '') {
            throw new MlServiceUnavailable('The ML service token is not configured.');
        }

        return Http::baseUrl((string) config('services.ml.url'))
            ->withToken($token)
            ->timeout($timeout ??= (float) config('services.ml.timeout'))
            ->connectTimeout(min((float) config('services.ml.connect_timeout'), $timeout))
            ->acceptJson()
            ->when(
                Context::get('request_id'),
                fn (PendingRequest $request, string $id) => $request->withHeaders(['X-Request-Id' => $id]),
            );
    }
}
