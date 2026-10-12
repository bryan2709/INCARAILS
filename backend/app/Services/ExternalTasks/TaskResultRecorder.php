<?php

namespace App\Services\ExternalTasks;

use App\Models\ExternalQuotaPool;
use App\Models\ExternalQuotaReservation;
use App\Models\ExternalTask;
use App\Models\ExternalTaskAttempt;
use App\Models\ExternalWorkerConfig;
use Illuminate\Support\Facades\DB;

class TaskResultRecorder
{
    public function __construct(private WorkerRegistry $workers) {}

    public function record(int $taskId, array $reservation, ExternalWorkerConfig $worker, int $status, ?array $body, int $retryAfter = 0): string
    {
        return DB::transaction(function () use ($taskId, $reservation, $worker, $status, $body, $retryAfter): string {
            $task = ExternalTask::whereKey($taskId)->lockForUpdate()->first();
            if (! $task || $task->lease_version !== $reservation['lease'] || $task->status !== 'processing') {
                return 'stale';
            }

            $transient = $status === 0 || $status === 429 || $status >= 500;
            $success = $status === 200 && is_array($body);
            ExternalTaskAttempt::whereKey($reservation['attempt'])->update([
                'finished_at' => now(),
                'http_status' => $status ?: null,
                'result_status' => $success ? 'success' : ($transient ? 'transient' : 'failed'),
            ]);
            ExternalQuotaReservation::where('attempt_id', $reservation['attempt'])->update([
                'reservation_status' => 'sent_or_uncertain',
            ]);

            if ($success) {
                $task->update([
                    'status' => 'completed',
                    'response_json' => $body,
                    'lease_until' => null,
                ]);
                $task->step->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);

                return 'success';
            }

            if ($status === 401) {
                $worker->credential->update([
                    'is_enabled' => false,
                ]);
            }

            $delay = $status === 429 ? max($worker->cooldown_seconds, $retryAfter, 60) : 5;
            if ($status === 429) {
                // Lock the same quota pool used by reservations, so cooldown updates cannot race.
                $pool = ExternalQuotaPool::whereKey($reservation['pool'])->lockForUpdate()->firstOrFail();
                $until = now()->addSeconds($delay);
                if ($pool->cooldown_until && $pool->cooldown_until->gt($until)) {
                    $until = $pool->cooldown_until;
                }

                $pool->update([
                    'cooldown_until' => $until,
                ]);
                $worker->credential->update([
                    'paused_until' => $until,
                ]);
            }

            $code = match (true) {
                $status === 403 => 'SOURCE_ACCESS_DENIED',
                $status === 401 => 'SOURCE_CREDENTIAL_INVALID',
                $transient => 'SOURCE_UNAVAILABLE',
                default => 'SOURCE_INVALID_RESPONSE',
            };
            $attempts = ExternalTaskAttempt::where('task_id', $task->id)->get();
            if (! $transient || $attempts->count() >= TaskLeaseManager::MAX_ATTEMPTS) {
                $task->update([
                    'status' => 'failed',
                    'lease_until' => null,
                ]);

                return $code;
            }

            $available = $this->workers->eligible($attempts->pluck('worker_id')->all(), $attempts->pluck('credential_id')->all())->isNotEmpty();
            $task->update([
                'status' => $available ? 'pending' : 'waiting_worker',
                'lease_until' => null,
                'not_before' => now()->addSeconds($delay),
            ]);

            return $available ? 'retry' : 'waiting_worker';
        }, 3);
    }
}
