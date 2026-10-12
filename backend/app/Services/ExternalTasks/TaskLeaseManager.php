<?php

namespace App\Services\ExternalTasks;

use App\Models\ExternalQuotaPool;
use App\Models\ExternalQuotaReservation;
use App\Models\ExternalTask;
use App\Models\ExternalTaskAttempt;
use App\Models\ExternalWorkerConfig;
use App\Models\ExternalWorkerRuntime;
use App\Models\Import;
use Illuminate\Support\Facades\DB;

class TaskLeaseManager
{
    public const MAX_ATTEMPTS = 3;

    public const LEASE_SECONDS = 45;

    public function __construct(private WorkerRegistry $workers) {}

    public function reserve(int $taskId, ExternalWorkerConfig $worker): array
    {
        ExternalWorkerRuntime::updateOrCreate([
            'worker_id' => $worker->id,
        ], [
            'instance_id' => gethostname() ?: 'worker',
            'last_heartbeat_at' => now(),
            'instance_lease_until' => now()->addSeconds(self::LEASE_SECONDS),
        ]);

        return DB::transaction(function () use ($taskId, $worker): array {
            $task = ExternalTask::whereKey($taskId)->lockForUpdate()->first();
            if (! $task || in_array($task->status, [
                'completed',
                'failed',
            ], true)) {
                return [
                    'action' => 'skip',
                ];
            }

            $import = Import::find($task->step?->import_id);
            if (! $import || in_array($import->status, [
                'completed',
                'failed',
            ], true)) {
                return [
                    'action' => 'skip',
                ];
            }

            if ($task->not_before && now()->lt($task->not_before)) {
                return $this->wait(max(1, (int) ceil(now()->diffInSeconds($task->not_before))));
            }

            if ($task->status === 'processing' && $task->lease_until && now()->lt($task->lease_until)) {
                return $this->wait(10);
            }

            if ($task->status === 'processing') {
                ExternalTaskAttempt::where('task_id', $task->id)->whereIn('result_status', [
                    'reserved',
                    'sent',
                ])
                    ->update([
                        'result_status' => 'uncertain',
                        'finished_at' => now(),
                    ]);
            }

            $attempts = ExternalTaskAttempt::where('task_id', $task->id)->get();
            if ($attempts->count() >= self::MAX_ATTEMPTS) {
                $task->update([
                    'status' => 'failed',
                    'lease_until' => null,
                ]);

                return [
                    'action' => 'fail',
                    'import' => $import->id,
                    'reason' => 'MAX_ATTEMPTS',
                ];
            }

            if ($attempts->contains('worker_id', $worker->id) || $attempts->contains('credential_id', $worker->credential_id)) {
                $available = $this->workers->eligible($attempts->pluck('worker_id')->all(), $attempts->pluck('credential_id')->all())->isNotEmpty();
                $task->update([
                    'status' => $available ? 'pending' : 'waiting_worker',
                ]);

                return $available ? $this->wait(15) : [
                    'action' => 'skip',
                ];
            }

            $credential = $worker->credential;
            if (! $credential->is_enabled || ($credential->paused_until && now()->lt($credential->paused_until))) {
                return $this->wait(30);
            }

            // The shared pool lock serializes quota reservation across all workers.

            $pool = ExternalQuotaPool::whereKey($credential->quota_pool_id)->lockForUpdate()->firstOrFail();
            $globalFull = ExternalQuotaReservation::where('quota_pool_id', $pool->id)
                ->where('reserved_at', '>', now()->subSeconds($pool->window_seconds))->count() >= $pool->max_requests;
            $workerFull = ExternalQuotaReservation::where('worker_id', $worker->id)
                ->where('reserved_at', '>', now()->subSeconds($worker->window_seconds))->count() >= $worker->max_requests;
            if (! $pool->is_enabled || ($pool->cooldown_until && now()->lt($pool->cooldown_until)) || $globalFull || $workerFull) {
                $task->update([
                    'status' => 'waiting_rate_limit',
                ]);

                return $this->wait(10);
            }

            $attempt = ExternalTaskAttempt::create([
                'task_id' => $task->id,
                'attempt_number' => $attempts->count() + 1,
                'worker_id' => $worker->id,
                'credential_id' => $credential->id,
                'started_at' => now(),
                'result_status' => 'reserved',
            ]);
            ExternalQuotaReservation::create([
                'task_id' => $task->id,
                'attempt_id' => $attempt->id,
                'worker_id' => $worker->id,
                'quota_pool_id' => $pool->id,
                'reserved_at' => now(),
                'reservation_status' => 'reserved',
            ]);
            $lease = $task->lease_version + 1;
            $task->update([
                'status' => 'processing',
                'assigned_worker_id' => $worker->id,
                'lease_version' => $lease,
                'lease_until' => now()->addSeconds(self::LEASE_SECONDS),
            ]);

            return [
                'action' => 'run',
                'attempt' => $attempt->id,
                'lease' => $lease,
                'resource' => $task->resource,
                'import' => $import->id,
                'pool' => $pool->id,
            ];
        }, 3);
    }

    private function wait(int $seconds): array
    {
        return [
            'action' => 'wait',
            'seconds' => $seconds,
        ];
    }
}
