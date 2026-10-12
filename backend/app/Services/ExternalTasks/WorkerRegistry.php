<?php

namespace App\Services\ExternalTasks;

use App\Models\ExternalWorkerConfig;
use Illuminate\Database\Eloquent\Collection;

class WorkerRegistry
{
    public function current(): ?ExternalWorkerConfig
    {
        return ExternalWorkerConfig::with('credential')
            ->where('worker_key', config('football.worker_key', 'worker-a'))
            ->where('is_enabled', true)->first();
    }

    public function token(ExternalWorkerConfig $worker): ?string
    {
        return $worker->credential?->is_enabled
            ? config('football.credentials.'.$worker->credential->secret_reference)
            : null;
    }

    public function eligible(array $workerIds = [], array $credentialIds = []): Collection
    {
        return ExternalWorkerConfig::with('credential')->where('is_enabled', true)
            ->whereNotIn('id', $workerIds)->whereNotIn('credential_id', $credentialIds)
            ->get()->filter(fn (ExternalWorkerConfig $worker) => filled($this->token($worker)));
    }
}
