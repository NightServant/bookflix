<?php

namespace App\Jobs;

use App\Ml\MlClient;
use App\Ml\MlServiceUnavailable;
use App\Models\MlJobRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One step of an ML pipeline, run by the Python service and watched from here. The first run starts the job and records an `ml_job_runs` row;
 * it then queues itself again, thirty seconds on and with the row's id, to copy the service's status until the step succeeded or failed (or two
 * hours pass). Polling is a fresh job each time, not a release, because a released job comes back with the state it was first queued with.
 *
 * A step chains the next one of its pipeline only after it succeeded (F15: `evaluate` needs the similarities of the candidate just trained,
 * so train, build-similarities and evaluate always go in that order). A failed step marks its row failed and ends the chain.
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

    /** Tries to get the service to accept the job before its run is recorded as failed. */
    public int $tries = 5;

    /**
     * @param  array<string, mixed>  $params
     * @param  list<array{0: string, 1: array<string, mixed>}>  $then  the steps after this one
     * @param  int|null  $mlJobRunId  the row of a step already started, which this job only watches
     */
    public function __construct(
        public string $name,
        public array $params = [],
        public array $then = [],
        public ?int $mlJobRunId = null,
    ) {
        $this->onQueue('default');
    }

    /**
     * The first job of a pipeline, carrying the rest of it.
     */
    public static function pipeline(string $key): self
    {
        $steps = self::PIPELINES[$key];
        [$name, $params] = array_shift($steps);

        return new self($name, $params, $steps);
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
     */
    private function start(MlClient $client): void
    {
        try {
            $runId = $client->startJob($this->name, $this->params);
        } catch (MlServiceUnavailable) {
            $this->release($this->backoff()[min($this->attempts(), count($this->backoff())) - 1] ?? 900);

            return;
        }

        $run = MlJobRun::create(['name' => $this->name, 'run_id' => $runId, 'status' => 'queued', 'params' => $this->params, 'started_at' => now()]);

        $this->again($run);
    }

    private function poll(MlClient $client, MlJobRun $run): void
    {
        if (in_array($run->status, ['succeeded', 'failed'], true)) {
            return;
        }

        try {
            $state = $client->job((string) $run->run_id);
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

        if ($status === 'succeeded' && $this->then !== []) {
            $next = $this->then;
            [$name, $params] = array_shift($next);

            self::dispatch($name, $params, $next);
        }
    }

    private function again(MlJobRun $run): void
    {
        self::dispatch($this->name, $this->params, $this->then, $run->id)->delay(self::POLL_SECONDS);
    }

    /**
     * The job gave up. A step that never started has no row yet and gets one, failed, so the page shows why the night's training did not happen;
     * one that was being watched has its row closed.
     */
    public function failed(Throwable $exception): void
    {
        if ($this->mlJobRunId !== null) {
            MlJobRun::whereKey($this->mlJobRunId)->whereNotIn('status', ['succeeded', 'failed'])
                ->update(['status' => 'failed', 'error' => $exception->getMessage(), 'finished_at' => now()]);

            return;
        }

        MlJobRun::create(['name' => $this->name, 'status' => 'failed', 'params' => $this->params, 'error' => $exception->getMessage(), 'started_at' => now(), 'finished_at' => now()]);
    }
}
