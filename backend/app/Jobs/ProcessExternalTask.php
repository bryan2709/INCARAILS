<?php

namespace App\Jobs;

use App\Models\ExternalTask;
use App\Models\ExternalTaskAttempt;
use App\Models\Import;
use App\Services\ExternalTasks\TaskLeaseManager;
use App\Services\ExternalTasks\TaskResultRecorder;
use App\Services\ExternalTasks\WorkerRegistry;
use App\Services\FootballClient;
use App\Services\ImportCoordinator;
use App\Services\ImportPersistence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessExternalTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 35;

    public int $tries = 0;

    public function __construct(public int $taskId) {}

    public function handle(
        FootballClient $client,
        ImportPersistence $persistence,
        ImportCoordinator $coordinator,
        WorkerRegistry $workers,
        TaskLeaseManager $leases,
        TaskResultRecorder $results,
    ): void {
        $worker = $workers->current();
        $token = $worker ? $workers->token($worker) : null;
        if (! $worker || ! $token) {
            $this->release(30);

            return;
        }

        $reservation = $leases->reserve($this->taskId, $worker);
        if ($reservation['action'] === 'wait') {
            $this->release(min(45, $reservation['seconds']));

            return;
        }

        if ($reservation['action'] === 'fail') {
            $coordinator->fail(Import::findOrFail($reservation['import']), $reservation['reason'], 'Se agotaron tres workers diferentes.');

            return;
        }

        if ($reservation['action'] !== 'run') {
            return;
        }

        // No database transaction remains open during the provider request.

        $status = 0;
        $body = null;
        $retryAfter = 0;
        try {
            ExternalTaskAttempt::whereKey($reservation['attempt'])->update([
                'sent_at' => now(),
                'result_status' => 'sent',
            ]);
            $response = $client->get($reservation['resource'], $token);
            $status = $response->status();
            $body = is_array($response->json()) ? $response->json() : null;
            $retryAfter = $client->retryAfter($response);
        } catch (Throwable) {
            $status = 0;
        }

        $outcome = $results->record($this->taskId, $reservation, $worker, $status, $body, $retryAfter);
        if ($outcome === 'success') {
            try {
                $persistence->completed(ExternalTask::with('step')->findOrFail($this->taskId), $body);
            } catch (Throwable $exception) {
                $coordinator->failFromException(Import::findOrFail($reservation['import']), $exception);
            }
        } elseif ($outcome === 'retry') {
            $task = ExternalTask::findOrFail($this->taskId);
            self::dispatch($this->taskId)->onQueue('football')->delay($task->not_before);
        } elseif (! in_array($outcome, [
            'waiting_worker',
            'stale',
        ], true)) {
            $coordinator->fail(Import::findOrFail($reservation['import']), $outcome, $client->errorMessage($status, $body));
        }
    }
}
