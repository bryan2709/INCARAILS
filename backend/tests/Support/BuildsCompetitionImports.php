<?php

namespace Tests\Support;

use App\Models\Competition;
use App\Models\ExternalTask;
use App\Models\Import;
use App\Services\ImportCoordinator;
use App\Services\ImportPersistence;

trait BuildsCompetitionImports
{
    protected function prepareImport(?Competition $competition = null, int $seasonId = 2509): Import
    {
        $competition ??= Competition::create(['external_id' => 2016, 'name' => 'Championship', 'type' => 'LEAGUE']);
        $import = app(ImportCoordinator::class)->start($competition, null);
        $this->completeTask($import, 'detail', [
            'id' => $competition->external_id,
            'name' => $competition->name,
            'currentSeason' => ['id' => $seasonId, 'startDate' => '2026-08-14', 'endDate' => '2027-05-01'],
        ]);

        return $import->fresh();
    }

    protected function completeTask(Import $import, string $key, array $response): void
    {
        $task = ExternalTask::whereHas('step', fn ($query) => $query->where('import_id', $import->id)->where('step_key', $key))->firstOrFail();
        $task->update(['status' => 'completed', 'response_json' => $response]);
        $task->step->update(['status' => 'completed']);
        app(ImportPersistence::class)->completed($task, $response);
    }

    protected function sourceTeams(int $competitionId = 2016, int $seasonId = 2509): array
    {
        return [
            'count' => 1,
            'competition' => ['id' => $competitionId],
            'season' => ['id' => $seasonId],
            'teams' => [[
                'id' => 59,
                'name' => 'Blackburn Rovers FC',
                'lastUpdated' => '2022-02-19T08:50:27Z',
                'squad' => [['id' => 4204, 'name' => 'Aynsley Pears', 'position' => 'Goalkeeper']],
            ]],
        ];
    }
}
