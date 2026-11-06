<?php

namespace App\Jobs;

use App\Ml\MlClient;
use App\Ml\MlRunNotFound;
use App\Ml\MlServiceUnavailable;
use App\Models\MlJobRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * One step of an ML pipeline, run by the Python service and watched from here. The first run starts the job and records an `ml_job_runs` row;
 * it then queues itself again, thirty seconds on and with the row's id, to copy the service's status until the step succeeded or failed (or two
 * hours pass). Polling is a fresh job each time, not a release, because a released job comes back with the state it was first queued with.
 *
 * A step chains the next one of its pipeline only after it succeeded (F15: `evaluate` needs the similarities of the candidate just trained,
 * so train, build-similarities and evaluate always go in that order). A failed step marks its row failed and ends the chain.
 *
 * The steps of a pipeline name the model version they work on: a train step reports `metrics.version_id`, and that id goes into the params of the
 * steps after it, so a second training that finishes in between can never be the one that gets its similarities built or evaluated. A train step
 * without it ends the chain. Only one run of a pipeline is accepted at a time (`dispatchPipeline`): the flag is held until the chain ends.
 */
class RunMlJob implements ShouldQueue
{
    use Queueable;

    /** The pipelines, each a list of `[job name, params]` steps in the order they run. */
    public const PIPELINES = [
        'popularity' => [['refresh-popularity', []]],
        'item-cf' => [['train-item-cf', []], ['build-similarities', ['methods' => ['item_cf']]], ['evaluate', ['kind' => 'item_cf']]],
        'lsa' => [['train-lsa', []], ['build-similarities', ['methods' => ['lsa']]], ['evaluate', ['kind' => 'lsa']]],
    ];

    /** Seconds between two looks at a running job. */
    private const POLL_SECONDS = 30;

    /** Hours after which a step still not finished is given up. */
    private const MAX_HOURS = 2;

    /** Hours a pipeline stays marked as running at most, in case its chain died without freeing it: three steps of up to MAX_HOURS each, and a little over. */
    private const LOCK_HOURS = 7;

    /** The steps that train a candidate version and report its id. */
    private const TRAIN_STEPS = ['train-lsa', 'train-item-cf'];

    /** Tries to get the service to accept the job before its run is recorded as failed. */
    public int $tries = 5;

    /**
     * @param  array<string, mixed>  $params
     * @param  list<array{0: string, 1: array<string, mixed>}>  $then  the steps after this one
     * @param  int|null  $mlJobRunId  the row of a step already started, which this job only watches
     * @param  string|null  $pipeline  the pipeline this step belongs to, whose running flag the last step frees
     * @param  string  $jobKey  names this job across its retries, so they find the row it opened
     */
    public function __construct(
        public string $name,
        public array $params = [],
        public array $then = [],
        public ?int $mlJobRunId = null,
        public ?string $pipeline = null,
        public string $jobKey = '',
    ) {
        $this->jobKey = $jobKey ?: (string) Str::uuid();
        $this->onQueue('default');
    }

    /**
     * The first job of a pipeline, carrying the rest of it.
     */
    public static function pipeline(string $key): self
    {
        $steps = self::PIPELINES[$key];
        [$name, $params] = array_shift($steps);

        return new self($name, $params, $steps, pipeline: $key);
    }

    /**
     * Queue a pipeline unless it is already running. False means a run is in progress and nothing was queued.
     */
    public static function dispatchPipeline(string $key): bool
    {
        if (! Cache::add(self::flag($key), true, now()->addHours(self::LOCK_HOURS))) {
            return false;
        }

        try {
            dispatch(self::pipeline($key));
        } catch (Throwable $exception) {
            Cache::forget(self::flag($key));

            throw $exception;
        }

        return true;
    }

    public static function isRunning(string $key): bool
    {
        return Cache::has(self::flag($key));
    }

    private static function flag(string $key): string
    {
        return 'ml:pipeline-running:'.$key;
    }

    private function free(): void
    {
        if ($this->pipeline !== null) {
            Cache::forget(self::flag($this->pipeline));
        }
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 300, 900];
    }

    public function handle(MlClient $client): void
    {
        if ($this->mlJobRunId === null) {
            $this->start($client);

            return;
        }

        $this->poll($client, MlJobRun::findOrFail($this->mlJobRunId));
    }

    /**
     * The service could not be reached or refused the job: nothing is recorded until the tries are spent (see `failed`), so a retry opens no second row.
     * A retry after the row was written (the next poll could not be queued) finds that row by the job's key and goes on from it.
     */
    private function start(MlClient $client): void
    {
        if ($run = MlJobRun::firstWhere('job_key', $this->jobKey)) {
            $this->again($run);

            return;
        }

        try {
            $runId = $client->startJob($this->name, $this->params);
        } catch (MlServiceUnavailable) {
            $this->release($this->backoff()[min($this->attempts(), count($this->backoff())) - 1] ?? 900);

            return;
        }

        $run = MlJobRun::create(['name' => $this->name, 'job_key' => $this->jobKey, 'run_id' => $runId, 'status' => 'queued', 'params' => $this->params, 'started_at' => now()]);

        $this->again($run);
    }

    private function poll(MlClient $client, MlJobRun $run): void
    {
        if (in_array($run->status, ['succeeded', 'failed'], true)) {
            return;
        }

        try {
            $state = $client->job((string) $run->run_id);
        } catch (MlRunNotFound) {
            $this->finish($run, 'failed', null, 'The ML service no longer knows this run (it was probably restarted).');

            return;
        } catch (MlServiceUnavailable) {
            // The service may be restarting; the run is given up at the deadline, not at the first missed answer.
            $this->expireOrPoll($run);

            return;
        }

        match ($state['status']) {
            'succeeded' => $this->finish($run, 'succeeded', $state['metrics'], null),
            'failed' => $this->finish($run, 'failed', $state['metrics'], $state['error'] ?? 'The job failed.'),
            default => $this->keepWatching($run, $state['status']),
        };
    }

    private function keepWatching(MlJobRun $run, string $status): void
    {
        $run->update(['status' => $status]);

        $this->expireOrPoll($run);
    }

    private function expireOrPoll(MlJobRun $run): void
    {
        if ($run->started_at->lt(now()->subHours(self::MAX_HOURS))) {
            $this->finish($run, 'failed', null, 'The job did not finish within two hours.');

            return;
        }

        $this->again($run);
    }

    /**
     * @param  array<string, mixed>|null  $metrics
     */
    private function finish(MlJobRun $run, string $status, ?array $metrics, ?string $error): void
    {
        $run->update(['status' => $status, 'metrics' => $metrics, 'error' => $error, 'finished_at' => now()]);

        if ($status !== 'succeeded' || $this->then === []) {
            $this->free();

            return;
        }

        $next = $this->then;

        if (in_array($this->name, self::TRAIN_STEPS, true)) {
            $versionId = $metrics['version_id'] ?? null;

            if (! is_int($versionId)) {
                MlJobRun::create(['name' => $next[0][0], 'status' => 'failed', 'params' => $next[0][1], 'error' => __('The :step step reported no version_id, so the steps after it were not run.', ['step' => $this->name]), 'started_at' => now(), 'finished_at' => now()]);
                $this->free();

                return;
            }

            $next = array_map(fn (array $step): array => [$step[0], [...$step[1], 'version_id' => $versionId]], $next);
        }

        [$name, $params] = array_shift($next);

        self::dispatch($name, $params, $next, null, $this->pipeline);
    }

    private function again(MlJobRun $run): void
    {
        self::dispatch($this->name, $this->params, $this->then, $run->id, $this->pipeline)->delay(self::POLL_SECONDS);
    }

    /**
     * The job gave up. A step that never started has no row yet and gets one, failed, so the page shows why the night's training did not happen;
     * one that was being watched has its row closed.
     */
    public function failed(Throwable $exception): void
    {
        $this->free();

        if ($this->mlJobRunId !== null) {
            MlJobRun::whereKey($this->mlJobRunId)->whereNotIn('status', ['succeeded', 'failed'])
                ->update(['status' => 'failed', 'error' => $exception->getMessage(), 'finished_at' => now()]);

            return;
        }

        MlJobRun::create(['name' => $this->name, 'status' => 'failed', 'params' => $this->params, 'error' => $exception->getMessage(), 'started_at' => now(), 'finished_at' => now()]);
    }
}
