<?php

namespace App\Services\ExternalTasks;

use App\Jobs\ProcessExternalTask;
use App\Models\Competition;
use App\Models\CompetitionSnapshot;
use App\Models\ExternalTask;
use App\Models\ExternalTaskAttempt;
use App\Models\Import;
use App\Services\ImportCoordinator;
use App\Services\ImportPersistence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TaskMaintenance
{
    public function __construct(
        private WorkerRegistry $workers,
        private ImportPersistence $persistence,
        private ImportCoordinator $coordinator,
    ) {}

    public function run(): void
    {
        $this->recoverTasks();
        $this->continueCompletedTasks();
        $this->cleanSnapshots();
    }

    private function recoverTasks(): void
    {
        ExternalTask::whereIn('status', [
            'waiting_worker',
            'processing',
            'pending',
        ])
            ->whereHas('step.import', fn ($query) => $query->whereNotIn('status', [
                'failed',
                'completed',
            ]))
            ->orderBy('id')->limit(100)->get()->each(function (ExternalTask $candidate): void {
                $exhaustedImport = DB::transaction(function () use ($candidate): ?int {
                    $task = ExternalTask::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                    if (! in_array($task->status, [
                        'waiting_worker',
                        'processing',
                        'pending',
                    ], true)) {
                        return null;
                    }

                    if ($task->status === 'processing' && $task->lease_until && now()->lt($task->lease_until)) {
                        return null;
                    }

                    if ($task->not_before && now()->lt($task->not_before)) {
                        return null;
                    }

                    if ($task->status === 'pending' && $task->updated_at->gt(now()->subMinute())) {
                        return null;
                    }

                    if ($task->status === 'processing') {
                        ExternalTaskAttempt::where('task_id', $task->id)->whereIn('result_status', ['reserved', 'sent'])
                            ->update(['result_status' => 'uncertain', 'finished_at' => now()]);
                    }

                    $attempts = ExternalTaskAttempt::where('task_id', $task->id)->get();
                    if ($attempts->count() >= TaskLeaseManager::MAX_ATTEMPTS) {
                        $task->update([
                            'status' => 'failed',
                            'lease_until' => null,
                        ]);

                        return $task->step->import_id;
                    }

                    if ($task->status === 'waiting_worker' && $task->updated_at->lt(now()->subMinutes(5))) {
                        Log::warning('Tarea en espera >5 minutos', [
                            'task_id' => $task->id,
                        ]);
                    }

                    $eligible = $this->workers->eligible($attempts->pluck('worker_id')->all(), $attempts->pluck('credential_id')->all());
                    if ($eligible->isEmpty()) {
                        $task->update([
                            'status' => 'waiting_worker',
                            'lease_until' => null,
                            'lease_version' => $task->lease_version + 1,
                        ]);

                        return null;
                    }

                    $task->update([
                        'status' => 'pending',
                        'lease_until' => null,
                        'lease_version' => $task->lease_version + 1,
                    ]);
                    ProcessExternalTask::dispatch($task->id)->onQueue('football');

                    return null;
                }, 3);

                if ($exhaustedImport) {
                    $this->coordinator->fail(Import::findOrFail($exhaustedImport), 'MAX_ATTEMPTS', 'Se agotaron tres workers diferentes.');
                }
            });
    }

    private function continueCompletedTasks(): void
    {
        ExternalTask::with('step')->where('status', 'completed')
            ->whereHas('step.import', fn ($query) => $query->whereNotIn('status', [
                'failed',
                'completed',
            ]))
            ->orderBy('id')->limit(50)->get()->each(function (ExternalTask $task): void {
                try {
                    $this->persistence->completed($task, $task->response_json ?? []);
                } catch (Throwable $exception) {
                    $this->coordinator->failFromException(Import::findOrFail($task->step->import_id), $exception);
                }
            });
    }

    private function cleanSnapshots(): void
    {
        $cutoff = now()->subHours(max(1, config('football.snapshot_retention_hours', 24)));
        $active = Competition::select('active_snapshot_id')->whereNotNull('active_snapshot_id');

        // Retention for failed staging starts at failure, not at the first creation.
        CompetitionSnapshot::where('build_status', '!=', 'published')
            ->whereHas('import', fn ($query) => $query->where('status', 'failed')->where('completed_at', '<', $cutoff))
            ->whereNotIn('id', $active)->limit(50)->get()->each(fn (CompetitionSnapshot $snapshot) => $snapshot->delete());
        CompetitionSnapshot::where('retired_at', '<', $cutoff)
            ->whereNotIn('id', $active)->limit(50)->get()->each(fn (CompetitionSnapshot $snapshot) => $snapshot->delete());
    }
}
