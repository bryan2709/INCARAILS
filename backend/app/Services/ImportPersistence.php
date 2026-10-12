<?php

namespace App\Services;

use App\Models\Competition;
use App\Models\CompetitionSeason;
use App\Models\ExternalTask;
use App\Models\Import;
use App\Services\Imports\EntityDetailPublisher;
use App\Services\Imports\SnapshotPublisher;
use App\Services\Imports\SourceCatalogMapper;
use App\Services\Imports\SourcePayloadValidator;
use DomainException;
use Illuminate\Support\Facades\DB;

class ImportPersistence
{
    public function __construct(
        private SourceCatalogMapper $mapper,
        private SourcePayloadValidator $validator,
        private SnapshotPublisher $publisher,
        private ImportCoordinator $coordinator,
        private EntityDetailPublisher $details,
    ) {}

    public function completed(ExternalTask $task, array $response): void
    {
        $step = $task->step;
        $import = Import::findOrFail($step->import_id);
        if (in_array($import->status, [
            'failed',
            'completed',
        ], true)) {
            return;
        }

        match ($step->step_key) {
            'catalog' => $this->catalog($import, $response),
            'detail' => $this->detail($import, $response),
            'teams' => $this->teams($import, $response),
            'team_detail' => $this->details->team($import, $response),
            'player_detail' => $this->details->player($import, $response),
            default => throw new DomainException('SOURCE_STEP_INVALID'),
        };
    }

    private function catalog(Import $import, array $data): void
    {
        if (! isset($data['competitions']) || ! is_array($data['competitions']) || ! count($data['competitions'])) {
            throw new DomainException('SOURCE_CATALOG_INVALID');
        }

        DB::transaction(function () use ($data, $import): void {
            foreach ($data['competitions'] as $item) {
                if (! is_array($item) || empty($item['id']) || empty($item['name'])) {
                    throw new DomainException('SOURCE_CATALOG_INVALID');
                }

                $competition = Competition::updateOrCreate([
                    'external_id' => $item['id'],
                ], [
                    'area_id' => $this->mapper->area($item['area'] ?? null),
                    'name' => $item['name'],
                    'code' => $item['code'] ?? null,
                    'type' => $item['type'] ?? null,
                    'emblem_url' => $item['emblem'] ?? null,
                    'source_plan' => $item['plan'] ?? null,
                    'source_updated_at' => $this->validator->timestamp($item['lastUpdated'] ?? null),
                    'catalog_fetched_at' => now(),
                ]);
                if (! empty($item['currentSeason']['id'])) {
                    $season = $this->mapper->season($competition, $item['currentSeason']);
                    $competition->update([
                        'current_season_id' => $season->id,
                    ]);
                }
            }

            $import->update([
                'status' => 'completed',
                'active_scope_key' => null,
                'completed_at' => now(),
            ]);
        }, 3);
    }

    private function detail(Import $import, array $data): void
    {
        $competition = Competition::findOrFail($import->competition_id);
        if ((int) ($data['id'] ?? -1) !== $competition->external_id) {
            throw new DomainException('SOURCE_COMPETITION_MISMATCH');
        }

        DB::transaction(function () use ($competition, $data, $import): void {
            $season = $this->mapper->season($competition, $data['currentSeason'] ?? []);
            $competition->update([
                'name' => $data['name'] ?? $competition->name,
                'code' => $data['code'] ?? $competition->code,
                'type' => $data['type'] ?? $competition->type,
                'emblem_url' => $data['emblem'] ?? $competition->emblem_url,
                'source_plan' => $data['plan'] ?? $competition->source_plan,
                'area_id' => $this->mapper->area($data['area'] ?? null),
                'current_season_id' => $season->id,
                'source_updated_at' => $this->validator->timestamp($data['lastUpdated'] ?? null),
            ]);
            $import->update([
                'season_id' => $season->id,
                'status' => 'processing',
                'started_at' => $import->started_at ?? now(),
            ]);
        }, 3);

        $year = substr($data['currentSeason']['startDate'], 0, 4);
        $this->coordinator->task($import, 'teams', 'competition_teams:'.$competition->external_id.':'.$year);
    }

    private function teams(Import $import, array $data): void
    {
        $competition = Competition::findOrFail($import->competition_id);
        $season = CompetitionSeason::findOrFail($import->season_id);
        $quality = $this->validator->teams($competition, $season, $data);
        $this->publisher->publish($import, $competition, $season, $data['teams'], $quality);
    }
}
