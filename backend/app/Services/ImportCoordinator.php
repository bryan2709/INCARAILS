<?php

namespace App\Services;

use App\Exceptions\InvalidSquadException;
use App\Jobs\ProcessExternalTask;
use App\Models\Competition;
use App\Models\ExternalQuotaPool;
use App\Models\ExternalTask;
use App\Models\Import;
use App\Models\ImportStep;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Throwable;

class ImportCoordinator
{
    public function ensureCatalog(): ?Import
    {
        if (Competition::whereNotNull('catalog_fetched_at')->exists()) {
            return null;
        }

        return DB::transaction(function () {
            ExternalQuotaPool::where('name', 'football-data-global')->lockForUpdate()->first();
            if (Competition::whereNotNull('catalog_fetched_at')->exists()) {
                return null;
            }

            if (Import::where('type', 'catalog')->exists()) {
                return Import::where('type', 'catalog')->latest('id')->first();
            }

            return $this->create('catalog', null, null, null);
        }, 3);
    }

    public function start(Competition $competition, ?int $userId, string $type = 'initial', ?Import $retry = null): Import
    {
        return DB::transaction(function () use ($competition, $userId, $type, $retry) {
            Competition::whereKey($competition->id)->lockForUpdate()->firstOrFail();
            $existing = Import::where('active_scope_key', 'competition:'.$competition->id)->first();
            if ($existing) {
                return $existing;
            }

            return $this->create($type, $competition, $userId, $retry);
        }, 3);
    }

    private function create(string $type, ?Competition $competition, ?int $userId, ?Import $retry): Import
    {
        $scope = $competition ? 'competition:'.$competition->id : 'catalog';
        $import = Import::create([
            'competition_id' => $competition?->id,
            'requested_by' => $userId,
            'retry_of_import_id' => $retry?->id,
            'type' => $type,
            'status' => 'pending',
            'active_scope_key' => $scope,
        ]);
        $this->task($import, $competition ? 'detail' : 'catalog', $competition ? 'competition_detail:'.$competition->external_id : 'catalog');

        return $import;
    }

    public function startEntity(Team|Player $entity, ?int $userId, ?Import $retry = null): Import
    {
        $kind = $entity instanceof Team ? 'team' : 'player';

        return DB::transaction(function () use ($entity, $userId, $kind, $retry): Import {
            $entity->newQuery()->whereKey($entity->id)->lockForUpdate()->firstOrFail();
            $scope = $kind.':'.$entity->id;
            $active = Import::where('active_scope_key', $scope)->first();
            if ($active) {
                return $active;
            }
            $import = Import::create([
                $kind.'_id' => $entity->id,
                'type' => $kind.'_refresh',
                'status' => 'pending',
                'requested_by' => $userId,
                'retry_of_import_id' => $retry?->id,
                'active_scope_key' => $scope,
            ]);
            $this->task($import, $kind.'_detail', $kind.'_detail:'.$entity->external_id);

            return $import;
        }, 3);
    }

    public function task(Import $import, string $key, string $resource): ExternalTask
    {
        $step = ImportStep::firstOrCreate([
            'import_id' => $import->id,
            'step_key' => $key,
        ], [
            'status' => 'pending',
        ]);
        $task = ExternalTask::firstOrCreate([
            'import_step_id' => $step->id,
            'request_key' => $key,
        ], [
            'resource' => $resource,
            'status' => 'pending',
        ]);
        if ($task->wasRecentlyCreated) {
            ProcessExternalTask::dispatch($task->id)->onQueue('football');
        }

        return $task;
    }

    public function fail(Import $import, string $code, string $message, ?array $qualitySummary = null): void
    {
        DB::transaction(function () use ($import, $code, $message, $qualitySummary) {
            $locked = Import::whereKey($import->id)->lockForUpdate()->first();
            if (! $locked || in_array($locked->status, [
                'completed',
                'failed',
            ])) {
                return;
            }

            $locked->update([
                'status' => 'failed',
                'error_code' => $code,
                'error_message' => mb_substr($message, 0, 2000),
                'quality_summary' => $qualitySummary,
                'active_scope_key' => null,
                'completed_at' => now(),
            ]);
        });
    }

    public function failFromException(Import $import, Throwable $exception): void
    {
        $sourceError = $exception instanceof \DomainException && str_starts_with($exception->getMessage(), 'SOURCE_');
        $code = $sourceError ? $exception->getMessage() : 'SOURCE_VALIDATION_ERROR';
        if (! $sourceError) {
            report($exception);
        }

        $this->fail(
            $import,
            $code,
            $exception instanceof InvalidSquadException
                ? $exception->publicMessage()
                : 'No se pudo validar o guardar la información del proveedor.',
            $exception instanceof InvalidSquadException ? $exception->qualitySummary : null,
        );
    }
}
